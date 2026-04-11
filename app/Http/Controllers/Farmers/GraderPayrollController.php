<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GraderPayrollController extends Controller
{
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

        return ApiResponse::success(
            compact('graders', 'pricingRows', 'salesTypes', 'rawMilkId'),
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

        // ── Milk collected per grader, segmented by destination ───────────────
        // milk_purchases.grader_id  → inventory_locations (the collection station)
        // milk_routes.location_id   → inventory_locations (the destination/transfer plant)
        // destination.price_list    → sales_type key used to look up grader_rate
        $query = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp',        'mp.id',  '=', 'mpi.purchase_id')
            ->join('inventory_locations as gl',   'gl.id',  '=', 'mp.grader_id')
            ->leftJoin('milk_routes as mr',        'mr.id',  '=', 'mp.route_id')
            ->leftJoin('inventory_locations as dl','dl.id',  '=', 'mr.location_id')
            ->whereBetween('mp.invoice_date', [$startDate, $endDate])
            ->where('mp.status', '!=', 'cancelled')
            ->whereNotNull('mp.grader_id');

        if ($graderId) {
            $query->where('mp.grader_id', $graderId);
        }

        $collectionRows = $query
            ->selectRaw('
                mp.grader_id                    AS grader_loc_id,
                gl.code                         AS grader_code,
                gl.name                         AS grader_name,
                mr.location_id                  AS dest_loc_id,
                dl.name                         AS dest_name,
                dl.price_list                   AS dest_price_list,
                COUNT(DISTINCT mpi.farmer_id)   AS farmer_count,
                SUM(mpi.quantity)               AS total_qty
            ')
            ->groupBy('mp.grader_id', 'gl.code', 'gl.name',
                      'mr.location_id', 'dl.name', 'dl.price_list')
            ->orderBy('gl.name')
            ->get();

        // ── Advances given to graders this period ─────────────────────────────
        $graderIds   = $collectionRows->pluck('grader_loc_id')->unique()->values()->toArray();
        $advancesMap = DB::table('grader_advances')
            ->whereIn('grader_id', $graderIds)
            ->whereBetween('advance_date', [$startDate, $endDate])
            ->selectRaw('grader_id, SUM(amount) AS advance_total')
            ->groupBy('grader_id')
            ->pluck('advance_total', 'grader_id');

        // ── Aggregate per grader ──────────────────────────────────────────────
        $graderMap = [];

        foreach ($collectionRows as $row) {
            $gid = $row->grader_loc_id;

            if (!isset($graderMap[$gid])) {
                $graderMap[$gid] = [
                    'grader_id'    => $gid,
                    'grader_code'  => $row->grader_code,
                    'grader_name'  => $row->grader_name,
                    'farmer_count' => 0,
                    'total_qty'    => 0.0,
                    'gross_pay'    => 0.0,
                    'segments'     => [],
                ];
            }

            $qty        = (float) $row->total_qty;
            $priceList  = $row->dest_price_list ?? '';
            $rateRow    = $priceList ? ($graderRates[$priceList] ?? null) : null;
            $rate       = $rateRow  ? (float) $rateRow->grader_rate : 0.0;
            $segAmount  = round($qty * $rate, 4);

            $graderMap[$gid]['farmer_count'] += (int) $row->farmer_count;
            $graderMap[$gid]['total_qty']    += $qty;
            $graderMap[$gid]['gross_pay']    += $segAmount;
            $graderMap[$gid]['segments'][]    = [
                'destination'  => $row->dest_name ?? '(no destination)',
                'price_list'   => $priceList,
                'qty'          => round($qty, 3),
                'rate'         => $rate,
                'amount'       => $segAmount,
                'has_rate'     => $rate > 0,
            ];
        }

        // ── Build final rows with advance & balance ───────────────────────────
        $graders = collect(array_values($graderMap))->map(function ($g) use ($advancesMap) {
            $gross   = round($g['gross_pay'], 2);
            $advance = round((float) ($advancesMap[$g['grader_id']] ?? 0), 2);
            $net     = max(0.0, round($gross - $advance, 2));
            // Balance = advance that couldn't be recovered this period (informational only)
            $balance = round(max(0.0, $advance - $gross), 2);

            return [
                'grader_id'    => $g['grader_id'],
                'grader_code'  => $g['grader_code'],
                'grader_name'  => $g['grader_name'],
                'farmer_count' => $g['farmer_count'],
                'total_qty'    => round($g['total_qty'], 3),
                'segments'     => $g['segments'],
                'gross_pay'    => $gross,
                'advance'      => $advance,
                'balance'      => $balance,
                'net_payable'  => $net,
            ];
        });

        $totals = [
            'grader_count'  => $graders->count(),
            'farmer_count'  => $graders->sum('farmer_count'),
            'total_qty'     => round($graders->sum('total_qty'), 3),
            'gross_pay'     => round($graders->sum('gross_pay'), 2),
            'total_advance' => round($graders->sum('advance'), 2),
            'total_balance' => round($graders->sum('balance'), 2),
            'net_payable'   => round($graders->sum('net_payable'), 2),
        ];

        return ApiResponse::success([
            'graders' => $graders,
            'totals'  => $totals,
            'month'   => $month,
            'year'    => $year,
        ], 'Grader payroll processed');
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
