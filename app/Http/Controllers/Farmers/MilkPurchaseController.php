<?php

namespace App\Http\Controllers\Farmers;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Kafka\KafkaProducer;
use App\Models\MilkPurchase;
use App\Models\MilkRoute;
use App\Models\MilkCollectionShift;
use App\Models\MilkQualitySetting;
use App\Services\Farmers\MilkPurchaseApprovalService;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MilkPurchaseController extends Controller
{
    public function __construct(
        private readonly MilkPurchaseApprovalService $approvalService
    ) {}

    // ── List / filter ────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $farmerId = $request->filled('farmer_id') ? (int) $request->farmer_id : null;

        // Always require a date range — default to today to avoid full-table scans
        $from = $request->filled('from') ? $request->from : now()->toDateString();
        $to   = $request->filled('to')   ? $request->to   : now()->toDateString();

        $query = MilkPurchase::with([
                'route:id,route_name',
                'shift:id,description',
                'items:id,purchase_id,farmer_id,quantity,unit_price,total_price,quality_status,rejection_reason',
                'items.farmer:id,farmer_no,full_name',
            ])
            ->select(['id', 'reference_no', 'invoice_date', 'created_at', 'status',
                      'total_qty', 'total_amount', 'route_id', 'shift_id', 'grader_id'])
            ->whereDate('invoice_date', '>=', $from)
            ->whereDate('invoice_date', '<=', $to)
            ->orderByDesc('invoice_date');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($farmerId) {
            $query->whereHas('items', fn ($q) => $q->where('farmer_id', $farmerId));
        }

        if ($request->filled('route_id')) {
            $query->where('route_id', (int) $request->route_id);
        }

        if ($request->filled('shift_id')) {
            $query->where('shift_id', (int) $request->shift_id);
        }

        // Graders only ever see their own collections — scoped by the
        // authenticated user's own user_id (created_by), never by a
        // client-supplied param, so one grader can't page through another's
        // data by guessing route/grader IDs. Mirrors the same auth()-derived
        // trust pattern already used in store() for the 'app'/'web' source flag.
        //
        // This used to scope by a grader_id derived from the user's
        // default_store code (inventory_locations lookup). That silently broke
        // for any account whose default_store doesn't map to a real inventory
        // location: store() would save grader_id as NULL while this filter
        // fell back to 0, and NULL never equals 0 in SQL — so the grader's own
        // just-submitted collections never appeared in their own list.
        // created_by is set directly from auth() at write time regardless of
        // location config, so it can't drift out of sync like that.
        if (auth()->user()?->hasRole('grader')) {
            $query->where('created_by', auth()->user()->user_id);
        }

        $limit = min((int) $request->get('limit', 100), 200);

        return ApiResponse::success($query->limit($limit)->get(), 'Milk purchases retrieved');
    }

    // ── Lightweight aggregated summary for dashboards (no raw rows) ──────────
    public function summary(Request $request): JsonResponse
    {
        $dateTo = $request->filled('to')
            ? Carbon::parse($request->to)->toDateString()
            : now()->toDateString();

        $dateFrom = $request->filled('from')
            ? Carbon::parse($request->from)->toDateString()
            : Carbon::parse($dateTo)->subDays(6)->toDateString();

        // Cap the range to avoid enormous payloads
        if (Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) > 90) {
            $dateFrom = Carbon::parse($dateTo)->subDays(89)->toDateString();
        }

        $summaryQuery = DB::table('milk_purchases')
            ->where('status', 'approved')
            ->whereBetween('invoice_date', [$dateFrom, $dateTo]);

        // This feeds the app's own landing-page "today" widget — a grader
        // should see their own collection totals, not the whole company's.
        // Same created_by scoping as index() for the same reason.
        if (auth()->user()?->hasRole('grader')) {
            $summaryQuery->where('created_by', auth()->user()->user_id);
        }

        $raw = $summaryQuery
            ->selectRaw('invoice_date AS date, SUM(total_qty) AS qty, SUM(total_amount) AS amount')
            ->groupBy('invoice_date')
            ->get()
            ->keyBy('date');

        // Fill every day in the range (no gaps)
        $days = [];
        for ($d = Carbon::parse($dateFrom); $d->lte(Carbon::parse($dateTo)); $d->addDay()) {
            $key = $d->toDateString();
            $row = $raw->get($key);
            $days[] = [
                'date'   => $key,
                'label'  => $d->format('D'),
                'qty'    => $row ? round((float) $row->qty, 3) : 0,
                'amount' => $row ? round((float) $row->amount, 2) : 0,
            ];
        }

        $today = collect($days)->firstWhere('date', now()->toDateString());

        // Rejected today — same scope/filters as the qty/amount query above,
        // but counted separately since rejected items are priced at zero and
        // never touch total_amount; a grader should see it, not have it
        // silently vanish from their own "today" widget.
        $rejectedQuery = DB::table('milk_purchase_items as i')
            ->join('milk_purchases as p', 'p.id', '=', 'i.purchase_id')
            ->where('i.quality_status', 'rejected')
            ->whereDate('p.invoice_date', now()->toDateString());

        if (auth()->user()?->hasRole('grader')) {
            $rejectedQuery->where('p.created_by', auth()->user()->user_id);
        }

        $rejectedToday = $rejectedQuery
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(i.quantity), 0) as qty')
            ->first();

        return ApiResponse::success([
            'today' => [
                'qty'            => $today['qty']    ?? 0,
                'amount'         => $today['amount'] ?? 0,
                'rejected_qty'   => round((float) ($rejectedToday->qty ?? 0), 1),
                'rejected_count' => (int) ($rejectedToday->cnt ?? 0),
            ],
            'days' => $days,
        ], 'Milk purchase summary');
    }

    // ── Reserve a reference number atomically (gap-free sequence) ────────────
    public function reserveReference(Request $request): JsonResponse
    {
        $draft = DB::transaction(function () use ($request) {
            $maxSeq = MilkPurchase::lockForUpdate()->max('seq') ?? 0;
            $seq    = $maxSeq + 1;
            $ref    = 'MPB-' . str_pad($seq, 6, '0', STR_PAD_LEFT);

            return MilkPurchase::create([
                'seq'          => $seq,
                'reference_no' => $ref,
                'invoice_date' => now()->toDateString(),
                'status'       => 'draft',
                'created_by'   => $request->user()?->user_id ?? 'system',
            ]);
        });

        return ApiResponse::success([
            'reference_no' => $draft->reference_no,
            'draft_id'     => $draft->id,
        ], 'Reference reserved');
    }

    // ── Discard an unused draft ───────────────────────────────────────────────
    public function discardDraft(string $id): JsonResponse
    {
        $draft = MilkPurchase::where('id', $id)->where('status', 'draft')->first();
        if ($draft) {
            $draft->delete();
        }
        return ApiResponse::success(null, 'Draft discarded');
    }

    // ── Submit a bulk milk collection batch ───────────────────────────────────
    // NOTE: accounting (stock movements + GL entries) is posted on APPROVAL,
    //       not on submission. store() only saves the batch and resolves prices.
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'draft_id'          => 'nullable|integer|exists:milk_purchases,id',
            'grader_id'         => 'nullable|integer|exists:inventory_locations,id',
            'route_id'          => 'required|integer|exists:milk_routes,id',
            'shift_id'          => 'required|integer|exists:milk_collection_shifts,id',
            'pricing_type'      => 'nullable|string|max:30',
            'reference_no'      => 'nullable|string|max:100',
            'invoice_date'      => 'required|date',
            'due_date'          => 'nullable|date',
            'items'             => 'required|array|min:1',
            'items.*.farmer_id' => 'required|integer|exists:farmers,id',
            'items.*.quantity'  => 'required|numeric|min:0.001',
            // Proof-of-collection — captured by the app per farmer, at the moment
            // that farmer's milk was weighed (not once for the whole batch, since
            // a grader's route covers many farmers at different locations/times).
            // All optional so older app builds and manual web entry still work.
            'items.*.latitude'        => 'nullable|numeric|between:-90,90',
            'items.*.longitude'       => 'nullable|numeric|between:-180,180',
            'items.*.gps_accuracy'    => 'nullable|numeric|min:0',
            'items.*.scale_connected' => 'nullable|boolean',
            'items.*.scale_device'    => 'nullable|string|max:20',
            'items.*.captured_at'     => 'nullable|date',
            // Quality control — tested per farmer at the point of collection.
            // All optional: a batch with no test data recorded is treated as
            // accepted by default (see quality_status column default), so
            // older app builds and manual web entry keep working unchanged.
            'items.*.smell_result'         => 'nullable|in:normal,abnormal',
            'items.*.alcohol_test_result'  => 'nullable|in:negative,positive',
            'items.*.density'              => 'nullable|numeric|min:0.9|max:1.2',
            'items.*.butterfat_percent'    => 'nullable|numeric|min:0|max:100',
            'items.*.adulteration_result'  => 'nullable|in:negative,positive',
        ]);

        $invoiceDate = $data['invoice_date'];
        $pricingType = strtolower($data['pricing_type'] ?? 'normal');
        $validItems  = array_filter($data['items'], fn ($i) => ($i['quantity'] ?? 0) > 0);

        // ── Duplicate guard — same farmer + same shift + same quantity ─────────
        // Defends against two distinct failure modes that both double-count a
        // farmer's milk: (a) the app accidentally adding the same weighed
        // entry twice into one batch (a double-tap on "Add" client-side —
        // now blocked there too, but this is the backstop), and (b) the same
        // batch getting submitted twice — a network retry, or an offline
        // record syncing more than once. (a) is checked within this request;
        // (b) is checked against what's already been recorded for this exact
        // shift/date. Quantity is part of the match (not just farmer+shift)
        // because a farmer can legitimately deliver more than once a shift —
        // it's the exact same weight recurring that's almost certainly a
        // duplicate, not two separate deliveries.
        $seenInRequest = [];
        $skippedDuplicates = 0;
        $validItems = array_values(array_filter($validItems, function ($item) use (&$seenInRequest, &$skippedDuplicates) {
            $sig = ($item['farmer_id'] ?? 0) . '|' . round((float) ($item['quantity'] ?? 0), 3);
            if (isset($seenInRequest[$sig])) {
                $skippedDuplicates++;
                return false;
            }
            $seenInRequest[$sig] = true;
            return true;
        }));

        if (! empty($data['draft_id'])) {
            // Re-submitting a draft replaces its items entirely (see below),
            // so there's nothing prior in *this* purchase to dedupe against.
        } else {
            $existingSignatures = DB::table('milk_purchase_items')
                ->join('milk_purchases', 'milk_purchases.id', '=', 'milk_purchase_items.purchase_id')
                ->where('milk_purchases.shift_id', $data['shift_id'])
                ->whereDate('milk_purchases.invoice_date', $invoiceDate)
                ->whereIn('milk_purchase_items.farmer_id', array_column($validItems, 'farmer_id'))
                ->get(['milk_purchase_items.farmer_id', 'milk_purchase_items.quantity'])
                ->map(fn ($r) => $r->farmer_id . '|' . round((float) $r->quantity, 3))
                ->flip();

            $validItems = array_values(array_filter($validItems, function ($item) use ($existingSignatures, &$skippedDuplicates) {
                $sig = ($item['farmer_id'] ?? 0) . '|' . round((float) ($item['quantity'] ?? 0), 3);
                if ($existingSignatures->has($sig)) {
                    $skippedDuplicates++;
                    return false;
                }
                return true;
            }));
        }

        if (empty($validItems)) {
            return ApiResponse::error(
                $skippedDuplicates > 0
                    ? "All {$skippedDuplicates} item(s) in this batch already exist for this farmer/shift/quantity — nothing new to record."
                    : 'No valid items to record.',
                422
            );
        }

        // Source is derived from who's actually authenticated, never from
        // client input — a request body flag would let anyone skip approval
        // just by claiming to be the app. Grader accounts only ever
        // authenticate through GraderAuthController::login (the Flutter
        // app's own login), so the 'grader' role is a reliable proxy for
        // "this came from the field, via the app."
        $source = auth()->user()?->hasRole('grader') ? 'app' : 'web';

        DB::beginTransaction();
        try {
            $totalQty    = collect($validItems)->sum('quantity');
            $totalAmount = 0.0;

            $headerData = [
                'grader_id'    => $data['grader_id'] ?? null,
                'route_id'     => $data['route_id'],
                'shift_id'     => $data['shift_id'],
                'pricing_type' => $pricingType,
                'source'       => $source,
                'reference_no' => $data['reference_no'] ?? null,
                'invoice_date' => $invoiceDate,
                'due_date'     => $data['due_date'] ?? null,
                'total_qty'    => $totalQty,
                'total_amount' => 0,
                'status'       => 'submitted',
                'created_by'   => auth()->user()?->user_id ?? '',
            ];

            if (! empty($data['draft_id'])) {
                $purchase = MilkPurchase::findOrFail($data['draft_id']);
                $purchase->items()->delete();   // clear any previous items on re-submit
                $purchase->update($headerData);
            } else {
                $purchase = MilkPurchase::create($headerData);
            }

            $ref = 'MPB-' . str_pad($purchase->id, 6, '0', STR_PAD_LEFT);

            // Bulk-resolve farmers and prices up front (3 queries total) instead of
            // looking them up per line — a several-thousand-line batch previously
            // ran ~4 sequential queries per line (farmer + member price + general
            // price + insert), which for large offline-sync batches took 30s-80s+
            // and blew past the app's request timeout.
            $farmerIds = collect($validItems)
                ->pluck('farmer_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $farmersById = DB::table('farmers')
                ->whereIn('id', $farmerIds)
                ->get()
                ->keyBy(fn ($f) => (int) $f->id);

            $memberNos = $farmersById->pluck('member_no')->filter()->unique()->values();

            $memberPricesByMember = DB::table('milk_prices_per_member')
                ->whereIn('supplier_id', $memberNos)
                ->where('begin_date', '<=', $invoiceDate)
                ->where('end_date',   '>=', $invoiceDate)
                ->get()
                ->groupBy('supplier_id');

            $generalPrices = DB::table('milk_prices')
                ->where('price_type', $pricingType)
                ->where('date_from', '<=', $invoiceDate)
                ->where('date_to',   '>=', $invoiceDate)
                ->orderByDesc('date_from')
                ->get();

            $resolvePriceFast = function (?object $farmer, float $qty) use ($memberPricesByMember, $generalPrices) {
                if ($farmer && $farmer->member_no) {
                    $memberPrice = ($memberPricesByMember->get($farmer->member_no) ?? collect())->first();
                    if ($memberPrice && $memberPrice->price > 0) {
                        return (float) $memberPrice->price;
                    }
                }

                // A band's to_qty <= its from_qty is this table's convention for
                // "no upper limit" (e.g. "60.01 and above" is stored as
                // from_qty=60.01, to_qty=0) — treating to_qty=0 literally meant
                // any quantity above the last finite band silently priced at 0,
                // zeroing out that farmer's gross value (and ESP credit score)
                // even though real milk was recorded.
                $generalPrice = $generalPrices->first(
                    fn ($p) => $p->from_qty <= $qty
                        && ($p->to_qty >= $qty || $p->to_qty <= $p->from_qty)
                );

                return ($generalPrice && $generalPrice->price > 0) ? (float) $generalPrice->price : 0.0;
            };

            // ── Quality control thresholds (loaded once for the whole batch) ────
            $qc = MilkQualitySetting::current();
            $evaluateQuality = function (array $line) use ($qc): array {
                $tested = isset($line['smell_result']) || isset($line['alcohol_test_result'])
                    || isset($line['density']) || isset($line['butterfat_percent'])
                    || isset($line['adulteration_result']);

                if (! $tested) {
                    return ['accepted', null];
                }

                $failures = [];

                if ($qc->reject_on_alcohol_positive && ($line['alcohol_test_result'] ?? null) === 'positive') {
                    $failures[] = 'alcohol test positive';
                }
                if ($qc->reject_on_adulteration_positive && ($line['adulteration_result'] ?? null) === 'positive') {
                    $failures[] = 'adulteration detected';
                }
                if ($qc->reject_on_abnormal_smell && ($line['smell_result'] ?? null) === 'abnormal') {
                    $failures[] = 'abnormal smell';
                }
                if (isset($line['butterfat_percent']) && (float) $line['butterfat_percent'] < $qc->min_butterfat_percent) {
                    $failures[] = 'butterfat below ' . $qc->min_butterfat_percent . '%';
                }
                if (isset($line['density'])) {
                    $d = (float) $line['density'];
                    if ($d < $qc->min_density || $d > $qc->max_density) {
                        $failures[] = 'density out of range (' . $qc->min_density . '-' . $qc->max_density . ')';
                    }
                }

                return empty($failures)
                    ? ['accepted', null]
                    : ['rejected', implode('; ', $failures)];
            };

            $tester = auth()->user()?->user_id ?? '';
            $now = now();
            $itemRows = [];
            foreach ($validItems as $line) {
                $farmerId   = (int) ($line['farmer_id'] ?? 0);
                $qty        = (float) $line['quantity'];
                $farmer     = $farmersById->get($farmerId);

                [$qualityStatus, $rejectionReason] = $evaluateQuality($line);

                // Rejected milk is recorded for reporting/farmer follow-up only —
                // it must never be paid for, so price it at zero rather than
                // skipping accounting later on a non-zero amount.
                $unitPrice  = $qualityStatus === 'rejected' ? 0.0 : $resolvePriceFast($farmer, $qty);
                $totalPrice = round($unitPrice * $qty, 4);
                $totalAmount += $totalPrice;

                $hasQualityData = isset($line['smell_result']) || isset($line['alcohol_test_result'])
                    || isset($line['density']) || isset($line['butterfat_percent'])
                    || isset($line['adulteration_result']);

                $itemRows[] = [
                    'purchase_id'      => $purchase->id,
                    'farmer_id'        => $farmerId,
                    'quantity'         => $qty,
                    'unit_price'       => $unitPrice,
                    'total_price'      => $totalPrice,
                    'unique_key'       => Str::uuid()->toString(),
                    'latitude'         => $line['latitude'] ?? null,
                    'longitude'        => $line['longitude'] ?? null,
                    'gps_accuracy'     => $line['gps_accuracy'] ?? null,
                    'scale_connected'  => $line['scale_connected'] ?? false,
                    'scale_device'     => $line['scale_device'] ?? null,
                    // The app sends ISO 8601 ("...T...Z"); a raw query builder
                    // insert (unlike an Eloquent model) does no date casting at
                    // all, so MySQL rejects that format for a datetime column.
                    'captured_at'         => isset($line['captured_at']) ? Carbon::parse($line['captured_at'])->toDateTimeString() : null,
                    'smell_result'        => $line['smell_result'] ?? null,
                    'alcohol_test_result' => $line['alcohol_test_result'] ?? null,
                    'density'             => $line['density'] ?? null,
                    'butterfat_percent'   => $line['butterfat_percent'] ?? null,
                    'adulteration_result' => $line['adulteration_result'] ?? null,
                    'quality_status'      => $qualityStatus,
                    'rejection_reason'    => $rejectionReason,
                    'tested_by'           => $hasQualityData ? $tester : null,
                    'tested_at'           => $hasQualityData ? $now : null,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }

            // Bulk insert — one query for the whole batch instead of one per line.
            foreach (array_chunk($itemRows, 500) as $chunk) {
                DB::table('milk_purchase_items')->insert($chunk);
            }

            $purchase->update([
                'reference_no' => $ref,
                'total_amount' => round($totalAmount, 4),
            ]);

            DB::commit();

            try {
                broadcast(new DashboardEvent('milk_purchase', 'collected', [
                    'ref'    => $ref,
                    'qty'    => round($totalQty, 2),
                    'amount' => round($totalAmount, 2),
                ]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }

            // Publish to Kafka when batch has 100+ farmer lines for downstream processing
            $itemCount = count($validItems);
            if ($itemCount >= 100) {
                try {
                    (new KafkaProducer())->publish('milk.purchase.bulk', [
                        'purchase_id'  => $purchase->id,
                        'reference_no' => $ref,
                        'route_id'     => $purchase->route_id,
                        'shift_id'     => $purchase->shift_id,
                        'grader_id'    => $purchase->grader_id,
                        'invoice_date' => $invoiceDate,
                        'item_count'   => $itemCount,
                        'total_qty'    => round($totalQty, 2),
                        'total_amount' => round($totalAmount, 2),
                    ], (string) $purchase->id);
                } catch (\Throwable $e) {
                    // Non-fatal — batch is already saved; Kafka failure must not roll back
                    \Log::warning('Kafka publish skipped for bulk purchase', [
                        'purchase_id' => $purchase->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            // App-sourced batches skip the manual approval queue entirely —
            // graders are the trusted first-line entry point, and requiring an
            // office click for every field batch just delays farmer payment
            // and stock posting for no real control benefit. Runs as its own
            // transaction, separate from the save above: if this fails, the
            // batch is still safely sitting in 'submitted' for manual approval
            // rather than being lost.
            $autoApproveFailed = false;
            if ($source === 'app') {
                DB::beginTransaction();
                try {
                    $this->approvalService->postApproval($purchase, auth()->user()?->user_id ?? 'app');
                    DB::commit();
                    try {
                        broadcast(new DashboardEvent('milk_purchase', 'approved', ['id' => $purchase->id]));
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
                    }
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $autoApproveFailed = true;
                    \Log::warning('Auto-approval failed for app-sourced milk purchase — left pending for manual approval', [
                        'purchase_id' => $purchase->id,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            $message = $source === 'app'
                ? ($autoApproveFailed
                    ? "Batch {$ref} submitted — {$totalQty} L, KES " . number_format($totalAmount, 2) . ' (auto-approval failed, pending manual review)'
                    : "Batch {$ref} auto-approved — {$totalQty} L, KES " . number_format($totalAmount, 2))
                : "Batch {$ref} submitted — {$totalQty} L, KES " . number_format($totalAmount, 2);

            if ($skippedDuplicates > 0) {
                $message .= " ({$skippedDuplicates} duplicate item(s) skipped — same farmer/shift/quantity already recorded)";
            }

            $rejectedCount = collect($itemRows)->where('quality_status', 'rejected')->count();
            if ($rejectedCount > 0) {
                $message .= " — {$rejectedCount} item(s) FAILED quality control and were not paid (recorded for follow-up only)";
            }

            return ApiResponse::created(
                $purchase->fresh(['items.farmer', 'route', 'shift', 'graderLocation']),
                $message
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::validationError(['error' => $e->getMessage()]);
        }
    }

    // ── Single purchase detail ────────────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $purchase = MilkPurchase::with(['items.farmer', 'route', 'shift', 'graderLocation'])
            ->findOrFail($id);
        return ApiResponse::success($purchase, 'Purchase retrieved');
    }

    // ── Approve a single purchase ─────────────────────────────────────────────
    // Posts all accounting records (GRN, supplier invoice, stock movements, GL).
    public function approve(int $id): JsonResponse
    {
        $purchase = MilkPurchase::findOrFail($id);

        if ($purchase->status === 'approved') {
            return ApiResponse::success($purchase, 'Already approved');
        }

        $user = auth()->user()?->user_id ?? '';

        DB::beginTransaction();
        try {
            $this->approvalService->postApproval($purchase, $user);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::validationError(['error' => 'Approval failed: ' . $e->getMessage()]);
        }

        broadcast(new DashboardEvent('milk_purchase', 'approved', ['id' => $purchase->id]));

        return ApiResponse::success(
            $purchase->fresh(['items.farmer', 'route', 'shift', 'graderLocation']),
            'Purchase approved — accounting records posted'
        );
    }

    // ── GL entries posted for this purchase (populated on approval) ──────────
    public function glEntries(int $id): JsonResponse
    {
        $purchase = MilkPurchase::with(['route', 'shift', 'graderLocation'])->find($id);
        if (! $purchase) return ApiResponse::notFound('Purchase not found');

        $company = DB::table('company_preferences')->first();

        $entries = GldTransaction::whereIn('type', [
                MilkPurchaseApprovalService::TYPE_GRN_RECEIVE,
                MilkPurchaseApprovalService::TYPE_SUPP_INVOICE,
            ])
            ->where('trans_no', $purchase->id)
            ->orderBy('id')
            ->get();

        $enriched = $entries->map(function ($e) {
            $accountName = DB::table('gl_accounts')
                ->where('code', $e->account_code)
                ->value('name') ?? $e->account_code;
            return [
                'tran_date'    => $e->tran_date,
                'account_code' => $e->account_code,
                'account_name' => $accountName,
                'narration'    => $e->narration,
                'amount'       => (float) $e->amount,
                'dimension1'   => null,
                'dimension2'   => null,
            ];
        });

        $totalDebit  = $enriched->where('amount', '>', 0)->sum('amount');
        $totalCredit = abs($enriched->where('amount', '<', 0)->sum('amount'));

        return ApiResponse::success([
            'company'  => $company,
            'delivery' => [   // keep key as 'delivery' for GLJournalModal compatibility
                'id'            => $purchase->id,
                'dn_no'         => $purchase->reference_no,
                'delivery_date' => $purchase->invoice_date instanceof \Carbon\Carbon
                    ? $purchase->invoice_date->toDateString()
                    : $purchase->invoice_date,
                'customer_name' => $purchase->graderLocation?->name,
                'debtor_no'     => $purchase->route?->route_name,
            ],
            'entries'      => $enriched,
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
        ], $purchase->status === 'approved' ? 'GL entries retrieved' : 'Purchase not yet approved — no GL entries posted');
    }

    // ── Reject a single purchase ──────────────────────────────────────────────
    public function reject(int $id, Request $request): JsonResponse
    {
        $purchase = MilkPurchase::findOrFail($id);

        if ($purchase->status === 'approved') {
            return ApiResponse::error('Cannot reject an already-approved purchase', 422);
        }

        $purchase->update([
            'status'        => 'rejected',
            'approved_by'   => $request->user()?->user_id ?? '',
            'reject_reason' => $request->input('reason'),
        ]);

        return ApiResponse::success($purchase->fresh(), 'Purchase rejected');
    }

    // ── Bulk approve ──────────────────────────────────────────────────────────
    // Previously published to Kafka for an external Go approval service to
    // consume — that service/broker was never deployed to this environment
    // (no consumer, no REST proxy reachable), so every bulk approval failed
    // with a connection error and nothing was ever actually approved.
    //
    // BulkApproveMilkPurchasesJob already existed (committed) doing exactly
    // this work correctly via the queue, it was just never dispatched from
    // here. Dispatching it now — a real `database` queue worker processes it
    // in the background and broadcasts the bulk_approved event on completion,
    // which the frontend already listens for.
    public function bulkApprove(Request $request): JsonResponse
    {

        
        $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);

        $user  = auth()->user()?->user_id ?? '';
        $count = count($request->ids);

        \App\Jobs\BulkApproveMilkPurchasesJob::dispatch($request->ids, $user);

        return ApiResponse::success(
            ['queued' => $count],
            "Processing {$count} purchase(s) — you will be notified when done"
        );
    }

    // ── Bulk reject ───────────────────────────────────────────────────────────
    public function bulkReject(Request $request): JsonResponse
    {
        $request->validate([
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'integer',
            'reason' => 'nullable|string|max:500',
        ]);

        $user = auth()->user()?->user_id ?? '';

        $count = MilkPurchase::whereIn('id', $request->ids)
            ->where('status', 'submitted')
            ->update([
                'status'        => 'rejected',
                'approved_by'   => $user,
                'reject_reason' => $request->input('reason'),
            ]);

        return ApiResponse::success(['updated' => $count], "{$count} purchase(s) rejected");
    }

    // ── Rejection report — failed-QC deliveries for office follow-up ─────────
    public function rejections(Request $request): JsonResponse
    {
        $from = $request->filled('from') ? $request->from : now()->subDays(6)->toDateString();
        $to   = $request->filled('to')   ? $request->to   : now()->toDateString();

        $query = DB::table('milk_purchase_items as i')
            ->join('milk_purchases as p', 'p.id', '=', 'i.purchase_id')
            ->join('farmers as f', 'f.id', '=', 'i.farmer_id')
            ->leftJoin('milk_routes as r', 'r.id', '=', 'p.route_id')
            ->leftJoin('milk_collection_shifts as s', 's.id', '=', 'p.shift_id')
            ->leftJoin('inventory_locations as loc', 'loc.id', '=', 'p.grader_id')
            ->where('i.quality_status', 'rejected')
            ->whereDate('p.invoice_date', '>=', $from)
            ->whereDate('p.invoice_date', '<=', $to);

        if ($request->filled('farmer_id')) {
            $query->where('i.farmer_id', (int) $request->farmer_id);
        }
        if ($request->filled('route_id')) {
            $query->where('p.route_id', (int) $request->route_id);
        }

        $rows = $query
            ->select([
                'i.id', 'i.quantity', 'i.rejection_reason', 'i.tested_by', 'i.tested_at',
                'i.smell_result', 'i.alcohol_test_result', 'i.adulteration_result',
                'i.density', 'i.butterfat_percent',
                'p.reference_no', 'p.invoice_date',
                'f.id as farmer_id', 'f.farmer_no', 'f.full_name', 'f.phone',
                'r.route_name', 's.description as shift_name',
                'loc.code as location_code', 'loc.name as location_name',
            ])
            ->orderByDesc('p.invoice_date')
            ->orderByDesc('i.tested_at')
            ->limit(500)
            ->get();

        return ApiResponse::success([
            'period' => ['from' => $from, 'to' => $to],
            'rows'   => $rows,
            'totals' => [
                'count' => $rows->count(),
                'qty'   => round((float) $rows->sum('quantity'), 1),
            ],
        ], 'Rejection report retrieved');
    }

    // ── Form data ────────────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'routes'           => MilkRoute::orderBy('route_name')->get(),
            'shifts'           => MilkCollectionShift::where('active', true)->orderBy('description')->get(),
            'default_tare_kg'  => (float) (GlSetting::first()?->default_tare_kg ?? 0.10),
            // Which QC tests the collection app should even ask for — an admin
            // may enable one, several, all, or none. Sent alongside the rest
            // of the form data the app already fetches on load, so there's no
            // extra round trip.
            'quality_settings' => MilkQualitySetting::current(),
        ], 'Form data retrieved');
    }

}
