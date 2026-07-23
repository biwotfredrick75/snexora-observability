<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GraderPayrollController extends Controller
{
    // gld_transactions.type code for Close Payroll GL postings — see the
    // type-code registry note in FarmerSupplierPaymentController.
    private const GL_TYPE = 51;

    // ── Form dropdown data ─────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        $graders = DB::table('inventory_locations')
            ->whereIn('type', ['grader', 'vendor'])
            ->where('inactive', false)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'price_list']);

        // Item sales prices for RAW MILK (shows grader rates per sales type / price list)
        $rawMilkId = $this->rawMilkStockId();
        $pricingRows = DB::table('item_sales_prices as isp')
            ->leftJoin('sales_types as st', 'st.type_name', '=', 'isp.sales_type')
            ->where('isp.stock_id', $rawMilkId)
            ->orderBy('isp.sales_type')
            ->get([
                'isp.id', 'isp.sales_type', 'isp.currency',
                'isp.price', 'isp.grader_rate', 'isp.special_rate',
                'isp.date_from', 'isp.date_to',
            ]);

        $salesTypes = DB::table('sales_types')->orderBy('type_name')->get(['id', 'type_name']);
        $company    = DB::table('company_preferences')->first(['name', 'phone', 'email', 'logo_filename']);

        return ApiResponse::success(
            compact('graders', 'pricingRows', 'salesTypes', 'rawMilkId', 'company'),
            'Form data'
        );
    }

    // ── Process Grader Payroll ─────────────────────────────────────────────────
    //  POST { month, year, grader_id? }
    public function process(Request $request): JsonResponse
    {
        $request->validate([
            'month'     => 'required|integer|between:1,12',
            'year'      => 'required|integer|min:2000|max:2100',
            'grader_id' => 'nullable|integer|exists:inventory_locations,id',
        ]);

        $month     = (int) $request->month;
        $year      = (int) $request->year;
        $graderId  = $request->grader_id;

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));

        // ── Grader rates from item_sales_prices (RAW MILK) ────────────────────
        // Key: sales_type (= price_list name on inventory_location)
        $rawMilkId = $this->rawMilkStockId();
        $graderRates = DB::table('item_sales_prices')
            ->where('stock_id', $rawMilkId)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereNull('date_from')->orWhere('date_from', '<=', $endDate);
            })
            ->where(function ($q) use ($startDate) {
                $q->whereNull('date_to')->orWhere('date_to', '>=', $startDate);
            })
            ->get(['sales_type', 'grader_rate'])
            ->keyBy('sales_type');   // e.g. ['Aspendos' => {grader_rate: 2.5}]

        // ── Milk COLLECTED per grader (from farmers) — informational only ──────
        // milk_purchases.grader_id → inventory_locations (the collection station).
        // This is what the grader picked up from farmers, before it's actually
        // hauled to a destination and confirmed received — see delivery query
        // below for what payroll is actually based on.
        $collectionQuery = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
            ->join('inventory_locations as gl', 'gl.id', '=', 'mp.grader_id')
            ->whereBetween('mp.invoice_date', [$startDate, $endDate])
            ->where('mp.status', '!=', 'cancelled')
            ->whereNotNull('mp.grader_id');

        if ($graderId) {
            $collectionQuery->where('mp.grader_id', $graderId);
        }

        $collectionRows = $collectionQuery
            ->selectRaw("
                mp.grader_id                    AS grader_loc_id,
                gl.code                         AS grader_code,
                gl.name                         AS grader_name,
                COUNT(DISTINCT mpi.farmer_id)   AS farmer_count,
                SUM(CASE WHEN mpi.quality_status = 'rejected' THEN 0 ELSE mpi.quantity END) AS collected_qty,
                SUM(CASE WHEN mpi.quality_status = 'rejected' THEN mpi.quantity ELSE 0 END) AS rejected_qty
            ")
            ->groupBy('mp.grader_id', 'gl.code', 'gl.name')
            ->orderBy('gl.name')
            ->get();

        // ── Milk DELIVERED per grader (as transporter), segmented by
        // destination — THIS is what the grader is actually paid on ──────────
        // milk_transfer_receptions.accepted_qty is the litreage confirmed to
        // have actually reached the destination (e.g. Main Factory) — anything
        // still in transit (dispatched, not yet received) isn't payable yet,
        // and anything rejected/short on arrival never counts either way.
        // Rate is keyed by "price type" (sales_type = a location's price_list):
        // the destination's price type overrides when set; otherwise it falls
        // back to the collecting grader/station's OWN price_list.
        $deliveryQuery = DB::table('milk_transfer_receptions as r')
            ->join('inventory_locations as gl', 'gl.id', '=', 'r.transporter_id')
            ->join('inventory_locations as dl', 'dl.id', '=', 'r.to_location_id')
            ->whereBetween('r.received_at', ["{$startDate} 00:00:00", "{$endDate} 23:59:59"]);

        if ($graderId) {
            $deliveryQuery->where('r.transporter_id', $graderId);
        }

        $deliveryRows = $deliveryQuery
            ->selectRaw("
                r.transporter_id                AS grader_loc_id,
                gl.code                         AS grader_code,
                gl.name                         AS grader_name,
                gl.price_list                   AS grader_price_list,
                r.to_location_id                AS dest_loc_id,
                dl.name                         AS dest_name,
                dl.price_list                   AS dest_price_list,
                SUM(r.accepted_qty)                    AS delivered_qty,
                SUM(r.rejected_qty + r.shortage_qty)   AS transfer_variance_qty
            ")
            ->groupBy('r.transporter_id', 'gl.code', 'gl.name', 'gl.price_list',
                      'r.to_location_id', 'dl.name', 'dl.price_list')
            ->orderBy('gl.name')
            ->get();

        // ── Advances given to graders this period ─────────────────────────────
        $graderIds = $collectionRows->pluck('grader_loc_id')
            ->merge($deliveryRows->pluck('grader_loc_id'))
            ->unique()->values()->toArray();
        $advancesMap = DB::table('grader_advances')
            ->whereIn('grader_id', $graderIds)
            ->whereBetween('advance_date', [$startDate, $endDate])
            ->where('settled', false)
            ->selectRaw('grader_id, SUM(amount) AS advance_total')
            ->groupBy('grader_id')
            ->pluck('advance_total', 'grader_id');

        // ── External agrovet/service-provider (ESP) sales not yet deducted ────
        // "transporter" party = the grader (they're the ones who haul the milk).
        $espMap = DB::table('esp_farmer_sales')
            ->where('party_type', 'transporter')
            ->whereIn('party_id', $graderIds)
            ->where('party_deducted', false)
            ->where('status', '!=', 'void')
            ->selectRaw('party_id AS grader_id, SUM(total_amount) AS esp_total')
            ->groupBy('party_id')
            ->pluck('esp_total', 'grader_id');

        // ── Charges raised against the grader as transporter — milk shortage
        // / rejection during a cooling-store transfer (MilkTransferReceptionController)
        // — not yet settled. Milk rejected at the point of collection (a
        // farmer-quality outcome, not a transport failure) is NOT charged
        // here — see rejected_qty below, which is informational only.
        $deductionMap = DB::table('grader_deductions')
            ->whereIn('grader_id', $graderIds)
            ->where('settled', false)
            ->selectRaw('grader_id, SUM(amount) AS deduction_total')
            ->groupBy('grader_id')
            ->pluck('deduction_total', 'grader_id');

        // ── Aggregate per grader ──────────────────────────────────────────────
        $graderMap = [];

        $blankGrader = fn ($gid, $code, $name) => [
            'grader_id'             => $gid,
            'grader_code'           => $code,
            'grader_name'           => $name,
            'farmer_count'          => 0,
            'collected_qty'         => 0.0,
            'rejected_qty'          => 0.0,
            'delivered_qty'         => 0.0,
            'transfer_variance_qty' => 0.0,
            'gross_pay'             => 0.0,
            'segments'              => [],
        ];

        // Collected — informational only, does not feed gross_pay.
        foreach ($collectionRows as $row) {
            $gid = $row->grader_loc_id;
            $graderMap[$gid] ??= $blankGrader($gid, $row->grader_code, $row->grader_name);

            $graderMap[$gid]['farmer_count']  += (int) $row->farmer_count;
            $graderMap[$gid]['collected_qty'] += (float) $row->collected_qty;
            $graderMap[$gid]['rejected_qty']  += (float) $row->rejected_qty;
        }

        // Delivered — this is what the grader is actually paid on.
        foreach ($deliveryRows as $row) {
            $gid = $row->grader_loc_id;
            $graderMap[$gid] ??= $blankGrader($gid, $row->grader_code, $row->grader_name);

            $qty        = (float) $row->delivered_qty;
            // Destination price type overrides when set; otherwise fall back
            // to the collecting grader/station's own price type.
            $priceList  = !empty($row->dest_price_list) ? $row->dest_price_list : ($row->grader_price_list ?? '');
            $rateRow    = $priceList ? ($graderRates[$priceList] ?? null) : null;
            $rate       = $rateRow  ? (float) $rateRow->grader_rate : 0.0;
            $segAmount  = round($qty * $rate, 4);

            $graderMap[$gid]['delivered_qty']         += $qty;
            $graderMap[$gid]['transfer_variance_qty']  += (float) $row->transfer_variance_qty;
            $graderMap[$gid]['gross_pay']              += $segAmount;
            $graderMap[$gid]['segments'][]              = [
                'destination'  => $row->dest_name,
                'price_list'   => $priceList,
                'qty'          => round($qty, 3),
                'rate'         => $rate,
                'amount'       => $segAmount,
                'has_rate'     => $rate > 0,
            ];
        }

        // ── Build final rows with advance, ESP deduction, shortage/rejection
        // deduction & balance ──────────────────────────────────────────────
        $graders = collect(array_values($graderMap))->map(function ($g) use ($advancesMap, $espMap, $deductionMap) {
            $gross      = round($g['gross_pay'], 2);
            $advance    = round((float) ($advancesMap[$g['grader_id']] ?? 0), 2);
            $esp        = round((float) ($espMap[$g['grader_id']] ?? 0), 2);
            $deduction  = round((float) ($deductionMap[$g['grader_id']] ?? 0), 2);
            $net        = max(0.0, round($gross - $advance - $esp - $deduction, 2));
            // Balance = amount that couldn't be recovered this period (informational only)
            $balance = round(max(0.0, ($advance + $esp + $deduction) - $gross), 2);

            return [
                'grader_id'             => $g['grader_id'],
                'grader_code'           => $g['grader_code'],
                'grader_name'           => $g['grader_name'],
                'farmer_count'          => $g['farmer_count'],
                'collected_qty'         => round($g['collected_qty'], 3),
                'rejected_qty'          => round($g['rejected_qty'], 3),
                'delivered_qty'         => round($g['delivered_qty'], 3),
                'transfer_variance_qty' => round($g['transfer_variance_qty'], 3),
                'segments'              => $g['segments'],
                'gross_pay'             => $gross,
                'advance'               => $advance,
                'esp_deduction'         => $esp,
                'shortage_deduction'    => $deduction,
                'balance'               => $balance,
                'net_payable'           => $net,
            ];
        });

        $totals = [
            'grader_count'          => $graders->count(),
            'farmer_count'          => $graders->sum('farmer_count'),
            'collected_qty'         => round($graders->sum('collected_qty'), 3),
            'rejected_qty'          => round($graders->sum('rejected_qty'), 3),
            'delivered_qty'         => round($graders->sum('delivered_qty'), 3),
            'transfer_variance_qty' => round($graders->sum('transfer_variance_qty'), 3),
            'gross_pay'             => round($graders->sum('gross_pay'), 2),
            'total_advance'         => round($graders->sum('advance'), 2),
            'total_esp'             => round($graders->sum('esp_deduction'), 2),
            'total_shortage'        => round($graders->sum('shortage_deduction'), 2),
            'total_balance'         => round($graders->sum('balance'), 2),
            'net_payable'           => round($graders->sum('net_payable'), 2),
        ];

        return ApiResponse::success([
            'graders'       => $graders,
            'totals'        => $totals,
            'month'         => $month,
            'year'          => $year,
            'closed_period' => $this->closedPeriodFor($month, $year, $graderId),
        ], 'Grader payroll processed');
    }

    // ── Confirm processing: mark this period's ESP deductions as settled ──────
    //  POST { month, year, grader_id? } — mirrors process(), but persists the
    //  ESP-sale deduction so it isn't pulled into a future period's net_payable.
    //  (Advances have no equivalent "confirm" step here — see close() below,
    //  which supersedes this for a full period close including GL posting.)
    public function settle(Request $request): JsonResponse
    {
        $request->validate([
            'month'     => 'required|integer|between:1,12',
            'year'      => 'required|integer|min:2000|max:2100',
            'grader_id' => 'nullable|integer|exists:inventory_locations,id',
        ]);

        $result  = $this->process($request);
        $data    = json_decode($result->getContent(), true)['data'] ?? [];
        $graders = $data['graders'] ?? [];

        $datePaid = now()->toDateString();
        $ref      = sprintf('GRPAY-%04d-%02d', (int) $request->year, (int) $request->month);

        DB::transaction(fn () => $this->settleEspAndShortageDeductions($graders, $datePaid, $ref));

        return ApiResponse::success($data, 'Grader payroll settled — ESP and shortage deductions recorded');
    }

    // ── Close Payroll: full period close ───────────────────────────────────────
    //  POST { month, year, grader_id? } — settles ESP, shortage-deduction AND
    //  advance debts for the period, posts a balanced entry to gld_transactions,
    //  and records a grader_payroll_periods row so this exact (or an
    //  overlapping) scope can't be closed/posted twice.
    public function close(Request $request): JsonResponse
    {
        $request->validate([
            'month'     => 'required|integer|between:1,12',
            'year'      => 'required|integer|min:2000|max:2100',
            'grader_id' => 'nullable|integer|exists:inventory_locations,id',
        ]);

        $month    = (int) $request->month;
        $year     = (int) $request->year;
        $graderId = $request->grader_id ? (int) $request->grader_id : null;

        if ($this->closedPeriodFor($month, $year, $graderId)) {
            return ApiResponse::validationError([
                'month' => ['This grader payroll period is already closed and posted to GL.'],
            ]);
        }

        $result  = $this->process($request);
        $data    = json_decode($result->getContent(), true)['data'] ?? [];
        $graders = $data['graders'] ?? [];
        $totals  = $data['totals']  ?? [];

        if (empty($graders)) {
            return ApiResponse::validationError([
                'month' => ['Nothing to close — no grader activity found for this period.'],
            ]);
        }

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));
        $datePaid  = now()->toDateString();
        $ref       = sprintf('GRPAY-%04d-%02d', $year, $month) . ($graderId ? '-' . $graderId : '');
        $userId    = $request->user()?->user_id ?? 'system';
        $userName  = $request->user()?->real_name ?? $userId;

        $period = DB::transaction(function () use (
            $graders, $totals, $startDate, $endDate, $datePaid, $ref,
            $month, $year, $graderId, $userId, $userName
        ) {
            // ── Settle sub-ledger debts ─────────────────────────────────────
            $this->settleEspAndShortageDeductions($graders, $datePaid, $ref);

            foreach ($graders as $g) {
                if (($g['advance'] ?? 0) > 0) {
                    DB::table('grader_advances')
                        ->where('grader_id', $g['grader_id'])
                        ->where('settled', false)
                        ->whereBetween('advance_date', [$startDate, $endDate])
                        ->update([
                            'settled'     => true,
                            'settled_at'  => $datePaid,
                            'settled_ref' => $ref,
                            'updated_at'  => now(),
                        ]);
                }
            }

            // ── Post to GL ───────────────────────────────────────────────────
            $glSettings   = DB::table('gl_settings')->first();
            $expenseCode  = $glSettings->grader_payroll_expense_account     ?? '';
            $payableCode  = $glSettings->graders_payable_account            ?? '';
            $advanceCode  = $glSettings->supplier_advance_account           ?? '';
            $recoveryCode = $glSettings->grader_deductions_recovery_account ?? '';

            $transNo = (DB::table('gld_transactions')
                ->where('type', self::GL_TYPE)
                ->lockForUpdate()
                ->max('trans_no') ?? 0) + 1;

            // Identity: gross_pay + total_balance == net_payable + total_advance
            //           + total_esp + total_shortage (see process() for the
            //           per-grader derivation) — so these four lines always balance.
            $expenseAmt  = round((float) ($totals['gross_pay'] ?? 0) + (float) ($totals['total_balance'] ?? 0), 2);
            $payableAmt  = round((float) ($totals['net_payable'] ?? 0), 2);
            $advanceAmt  = round((float) ($totals['total_advance'] ?? 0), 2);
            $recoveryAmt = round((float) ($totals['total_esp'] ?? 0) + (float) ($totals['total_shortage'] ?? 0), 2);

            $narration = 'Grader payroll — ' . $ref;
            $line = fn ($code, $amount) => [
                'trans_no'     => $transNo,
                'type'         => self::GL_TYPE,
                'tran_date'    => $datePaid,
                'account_code' => $code,
                'reference'    => $ref,
                'narration'    => $narration,
                'amount'       => $amount,
                'created_by'   => $userId,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];

            $glRows = [];
            if ($expenseCode  && $expenseAmt  > 0) $glRows[] = $line($expenseCode, $expenseAmt);
            if ($payableCode  && $payableAmt  > 0) $glRows[] = $line($payableCode, -$payableAmt);
            if ($advanceCode  && $advanceAmt  > 0) $glRows[] = $line($advanceCode, -$advanceAmt);
            if ($recoveryCode && $recoveryAmt > 0) $glRows[] = $line($recoveryCode, -$recoveryAmt);

            if (!empty($glRows)) {
                DB::table('gld_transactions')->insert($glRows);
            }

            // ── Persist the close/period record ────────────────────────────
            $periodId = DB::table('grader_payroll_periods')->insertGetId([
                'month'          => $month,
                'year'           => $year,
                'grader_id'      => $graderId,
                'reference'      => $ref,
                'total_gross'    => $totals['gross_pay']      ?? 0,
                'total_advance'  => $totals['total_advance']  ?? 0,
                'total_esp'      => $totals['total_esp']      ?? 0,
                'total_shortage' => $totals['total_shortage'] ?? 0,
                'total_balance'  => $totals['total_balance']  ?? 0,
                'net_payable'    => $totals['net_payable']    ?? 0,
                'gl_trans_no'    => $transNo,
                'posted_by'      => $userName,
                'posted_at'      => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return DB::table('grader_payroll_periods')->where('id', $periodId)->first();
        });

        return ApiResponse::success([
            'period'  => $period,
            'graders' => $graders,
            'totals'  => $totals,
        ], 'Grader payroll closed — GL posted and advances/ESP/shortage deductions settled');
    }

    // ── Shared ESP + shortage-deduction settlement (used by settle() & close()) ─
    private function settleEspAndShortageDeductions(array $graders, string $datePaid, string $ref): void
    {
        foreach ($graders as $g) {
            if (($g['esp_deduction'] ?? 0) > 0) {
                $remaining = (float) $g['esp_deduction'];
                $sales = DB::table('esp_farmer_sales')
                    ->where('party_type', 'transporter')
                    ->where('party_id', $g['grader_id'])
                    ->where('party_deducted', false)
                    ->where('status', '!=', 'void')
                    ->orderBy('id')
                    ->get(['id', 'total_amount']);

                foreach ($sales as $sale) {
                    if ($remaining <= 0.005) break;
                    DB::table('esp_farmer_sales')->where('id', $sale->id)->update([
                        'party_deducted'     => true,
                        'party_deducted_at'  => $datePaid,
                        'party_deducted_ref' => $ref,
                        'updated_at'         => now(),
                    ]);
                    $remaining -= (float) $sale->total_amount;
                }
            }

            if (($g['shortage_deduction'] ?? 0) > 0) {
                $remaining = (float) $g['shortage_deduction'];
                $deductions = DB::table('grader_deductions')
                    ->where('grader_id', $g['grader_id'])
                    ->where('settled', false)
                    ->orderBy('id')
                    ->get(['id', 'amount']);

                foreach ($deductions as $deduction) {
                    if ($remaining <= 0.005) break;
                    DB::table('grader_deductions')->where('id', $deduction->id)->update([
                        'settled'     => true,
                        'settled_at'  => $datePaid,
                        'settled_ref' => $ref,
                        'updated_at'  => now(),
                    ]);
                    $remaining -= (float) $deduction->amount;
                }
            }
        }
    }

    // ── Find a grader_payroll_periods row covering (or overlapping) the given
    // scope. A specific-grader request is blocked/flagged by either a matching
    // per-grader close OR a prior "all graders" close; an "all graders"
    // request is blocked/flagged by ANY existing row for that month/year,
    // since it would otherwise double-post graders already closed individually.
    private function closedPeriodFor(int $month, int $year, ?int $graderId)
    {
        $query = DB::table('grader_payroll_periods')->where('month', $month)->where('year', $year);

        if ($graderId) {
            $query->where(function ($q) use ($graderId) {
                $q->where('grader_id', $graderId)->orWhereNull('grader_id');
            });
        }

        return $query->orderByDesc('id')->first();
    }

    // ── Record advance to a grader ─────────────────────────────────────────────
    public function storeAdvance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'grader_id'    => 'required|integer|exists:inventory_locations,id',
            'advance_date' => 'required|date',
            'amount'       => 'required|numeric|min:0.01',
            'reference'    => 'nullable|string|max:60',
            'notes'        => 'nullable|string',
        ]);

        $data['created_by'] = $request->user()?->real_name ?? $request->user()?->user_id ?? 'system';

        $id = DB::table('grader_advances')->insertGetId(array_merge($data, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return ApiResponse::created(
            DB::table('grader_advances')->where('id', $id)->first(),
            'Advance recorded'
        );
    }

    // ── Save / update a grader rate (item_sales_prices row for RAW MILK) ───────
    public function saveRate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'           => 'nullable|integer|exists:item_sales_prices,id',
            'sales_type'   => 'required|string|max:100',
            'currency'     => 'nullable|string|max:50',
            'price'        => 'nullable|numeric|min:0',
            'grader_rate'  => 'required|numeric|min:0',
            'special_rate' => 'nullable|numeric|min:0',
            'date_from'    => 'nullable|date',
            'date_to'      => 'nullable|date|after_or_equal:date_from',
        ]);

        $rawMilkId = $this->rawMilkStockId();
        $now       = now();

        if (!empty($data['id'])) {
            DB::table('item_sales_prices')
                ->where('id', $data['id'])
                ->where('stock_id', $rawMilkId)
                ->update([
                    'grader_rate'  => $data['grader_rate'],
                    'special_rate' => $data['special_rate'] ?? 0,
                    'price'        => $data['price'] ?? 0,
                    'date_from'    => $data['date_from'] ?? null,
                    'date_to'      => $data['date_to'] ?? null,
                    'updated_at'   => $now,
                ]);
            $row = DB::table('item_sales_prices')->where('id', $data['id'])->first();
        } else {
            $id = DB::table('item_sales_prices')->insertGetId([
                'stock_id'     => $rawMilkId,
                'currency'     => $data['currency'] ?? 'KES',
                'sales_type'   => $data['sales_type'],
                'price'        => $data['price'] ?? 0,
                'grader_rate'  => $data['grader_rate'],
                'special_rate' => $data['special_rate'] ?? 0,
                'date_from'    => $data['date_from'] ?? null,
                'date_to'      => $data['date_to'] ?? null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $row = DB::table('item_sales_prices')->where('id', $id)->first();
        }

        return ApiResponse::success($row, 'Rate saved');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function rawMilkStockId(): string
    {
        return DB::table('items')
            ->where(fn ($q) => $q
                ->where('long_description', 'like', '%RAW MILK%')
                ->orWhere('description', 'like', '%RAW MILK%')
            )
            ->value('stock_id') ?? '0001';
    }
}
