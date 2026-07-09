<?php

namespace App\Http\Controllers\Farmers;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Kafka\KafkaProducer;
use App\Models\MilkPurchase;
use App\Models\MilkRoute;
use App\Models\MilkCollectionShift;
use App\Services\Farmers\MilkPurchaseApprovalService;
use App\Models\GldTransaction;
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
                'items:id,purchase_id,farmer_id,quantity,unit_price,total_price',
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

        $raw = DB::table('milk_purchases')
            ->where('status', 'approved')
            ->whereBetween('invoice_date', [$dateFrom, $dateTo])
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

        return ApiResponse::success([
            'today' => [
                'qty'    => $today['qty']    ?? 0,
                'amount' => $today['amount'] ?? 0,
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
        ]);

        $invoiceDate = $data['invoice_date'];
        $pricingType = strtolower($data['pricing_type'] ?? 'normal');
        $validItems  = array_filter($data['items'], fn ($i) => ($i['quantity'] ?? 0) > 0);

        DB::beginTransaction();
        try {
            $totalQty    = collect($validItems)->sum('quantity');
            $totalAmount = 0.0;

            $headerData = [
                'grader_id'    => $data['grader_id'] ?? null,
                'route_id'     => $data['route_id'],
                'shift_id'     => $data['shift_id'],
                'pricing_type' => $pricingType,
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

            // Save each farmer line with resolved price
            foreach ($validItems as $line) {
                $farmerId  = $line['farmer_id'] ?? null;
                $qty       = (float) $line['quantity'];
                $farmer    = $farmerId ? DB::table('farmers')->where('id', $farmerId)->first() : null;
                $unitPrice = $this->resolvePrice($farmer, $qty, $pricingType, $invoiceDate);
                $totalPrice = round($unitPrice * $qty, 4);
                $totalAmount += $totalPrice;
                $purchase->items()->create([
                    'farmer_id'   => $farmerId,
                    'quantity'    => $qty,
                    'unit_price'  => $unitPrice,
                    'total_price' => $totalPrice,
                    'unique_key'  => Str::uuid()->toString(),
                ]);
            }

            $purchase->update([
                'reference_no' => $ref,
                'total_amount' => round($totalAmount, 4),
            ]);

            DB::commit();

            broadcast(new DashboardEvent('milk_purchase', 'collected', [
                'ref'    => $ref,
                'qty'    => round($totalQty, 2),
                'amount' => round($totalAmount, 2),
            ]));

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

            return ApiResponse::created(
                $purchase->load(['items.farmer', 'route', 'shift', 'graderLocation']),
                "Batch {$ref} submitted — {$totalQty} L, KES " . number_format($totalAmount, 2)
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

    // ── Form data ────────────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'routes' => MilkRoute::orderBy('route_name')->get(),
            'shifts' => MilkCollectionShift::where('active', true)->orderBy('description')->get(),
        ], 'Form data retrieved');
    }

    // ── Price resolution ─────────────────────────────────────────────────────
    // Priority: 1. member-specific price  2. general price band  3. zero
    private function resolvePrice(
        ?object $farmer,
        float   $qty,
        string  $pricingType,
        string  $date
    ): float {
        if ($farmer && $farmer->member_no) {
            $memberPrice = DB::table('milk_prices_per_member')
                ->where('supplier_id', $farmer->member_no)
                ->where('begin_date', '<=', $date)
                ->where('end_date',   '>=', $date)
                ->first();

            if ($memberPrice && $memberPrice->price > 0) {
                return (float) $memberPrice->price;
            }
        }

        $generalPrice = DB::table('milk_prices')
            ->where('price_type', $pricingType)
            ->where('from_qty',  '<=', $qty)
            ->where('to_qty',    '>=', $qty)
            ->where('date_from', '<=', $date)
            ->where('date_to',   '>=', $date)
            ->orderByDesc('date_from')
            ->first();

        if ($generalPrice && $generalPrice->price > 0) {
            return (float) $generalPrice->price;
        }

        return 0.0;
    }
}
