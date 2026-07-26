<?php

namespace App\Http\Controllers\Manufacturing;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WorkOrder;
use App\Models\WorkOrderLabour;
use App\Models\WorkOrderOverhead;
use App\Services\Manufacturing\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    public function __construct(private WorkOrderService $service) {}

    // ── List / filter ─────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = WorkOrder::with(['bom', 'workCentre'])
            ->orderByDesc('due_date');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('product_code')) {
            $query->where('product_code', $request->product_code);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn ($b) => $b
                ->where('wo_no', 'like', "%$q%")
                ->orWhere('product_description', 'like', "%$q%")
                ->orWhere('product_code', 'like', "%$q%")
            );
        }

        return ApiResponse::success($query->paginate(30), 'Work orders retrieved');
    }

    // ── Show single WO with all details ──────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $wo = WorkOrder::with(['bom', 'workCentre', 'mfgType', 'items', 'labour', 'overhead', 'byproducts'])->findOrFail($id);
        $stages = DB::table('work_order_stages')->where('work_order_id', $id)->orderBy('stage_order')->get();
        $data = $wo->toArray();
        $data['stages'] = $stages;
        return ApiResponse::success($data, 'Work order retrieved');
    }

    // ── Create (Draft) ────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_code'        => 'required|string|max:20|exists:items,stock_id',
            'bom_id'              => 'required|integer|exists:boms,id',
            'mfg_type_id'         => 'nullable|integer|exists:manufacturing_types,id',
            'work_centre_id'      => 'nullable|integer|exists:work_centres,id',
            'planned_qty'         => 'required|numeric|min:0.0001',
            'unit'                => 'nullable|string|max:20',
            'start_date'          => 'required|date',
            'due_date'            => 'required|date|after_or_equal:start_date',
            'location_code'       => 'nullable|string|max:20|exists:inventory_locations,code',
            'output_location_code'=> 'nullable|string|max:20|exists:inventory_locations,code',
            'notes'               => 'nullable|string',
        ]);

        $wo = DB::transaction(function () use ($data, $request) {
            $maxId = WorkOrder::lockForUpdate()->max('id') ?? 0;
            $woNo  = 'WO-' . now()->format('Y') . '-' . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);

            $item = DB::table('items')->where('stock_id', $data['product_code'])->first();

            // Auto-inherit mfg_type_id from BOM if not explicitly provided
            $mfgTypeId = $data['mfg_type_id']
                ?? DB::table('boms')->where('id', $data['bom_id'])->value('mfg_type_id');

            $wo = WorkOrder::create([
                'wo_no'               => $woNo,
                'product_code'        => $data['product_code'],
                'product_description' => $item?->description ?? '',
                'bom_id'              => $data['bom_id'],
                'mfg_type_id'         => $mfgTypeId,
                'work_centre_id'      => $data['work_centre_id'] ?? null,
                'planned_qty'         => $data['planned_qty'],
                'unit'                => $data['unit'] ?? ($item?->units ?? ''),
                'start_date'          => $data['start_date'],
                'due_date'            => $data['due_date'],
                'location_code'       => $data['location_code'] ?? '',
                'output_location_code'=> $data['output_location_code'] ?? '',
                'notes'               => $data['notes'] ?? null,
                'status'              => WorkOrderService::STATUS_DRAFT,
                'created_by'          => auth()->user()?->user_id ?? '',
            ]);

            // Update WO number with actual ID
            $wo->update(['wo_no' => 'WO-' . now()->format('Y') . '-' . str_pad($wo->id, 4, '0', STR_PAD_LEFT)]);

            return $wo->fresh();
        });

        return ApiResponse::created($wo, "Work order {$wo->wo_no} created");
    }

    // ── Update (Draft only) ───────────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $wo = WorkOrder::findOrFail($id);
        if ($wo->status !== WorkOrderService::STATUS_DRAFT) {
            return ApiResponse::error('Only Draft work orders can be edited.', 422);
        }

        $data = $request->validate([
            'product_code'        => 'required|string|max:20|exists:items,stock_id',
            'bom_id'              => 'required|integer|exists:boms,id',
            'mfg_type_id'         => 'nullable|integer|exists:manufacturing_types,id',
            'work_centre_id'      => 'nullable|integer|exists:work_centres,id',
            'planned_qty'         => 'required|numeric|min:0.0001',
            'unit'                => 'nullable|string|max:20',
            'start_date'          => 'required|date',
            'due_date'            => 'required|date|after_or_equal:start_date',
            'location_code'       => 'nullable|string|max:20|exists:inventory_locations,code',
            'output_location_code'=> 'nullable|string|max:20|exists:inventory_locations,code',
            'notes'               => 'nullable|string',
        ]);

        $item = DB::table('items')->where('stock_id', $data['product_code'])->first();

        // Auto-inherit from BOM if not provided
        if (empty($data['mfg_type_id'])) {
            $data['mfg_type_id'] = DB::table('boms')->where('id', $data['bom_id'])->value('mfg_type_id');
        }

        $wo->update(array_merge($data, ['product_description' => $item?->description ?? '']));

        return ApiResponse::updated($wo->fresh(), 'Work order updated');
    }

    // ── Status transitions ────────────────────────────────────────────────────

    public function release(int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $user = auth()->user()?->user_id ?? '';
        DB::beginTransaction();
        try {
            $this->service->release($wo, $user);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), 422);
        }
        return ApiResponse::success($wo->fresh(['items', 'bom', 'workCentre']), 'Work order released — material requirements loaded');
    }

    public function issueAll(Request $request, int $id): JsonResponse
    {
        $wo        = WorkOrder::findOrFail($id);
        $user      = auth()->user()?->user_id ?? '';
        $targetQty = $request->filled('actual_qty') ? (float) $request->actual_qty : null;

        // Optional per-line overrides staged in the UI before this click (never
        // posted to stock on their own — this is the one action that posts).
        // Lines not listed keep scaling proportionally from actual_qty.
        $lineOverrides = [];
        foreach ($request->input('lines', []) as $row) {
            if (isset($row['item_id'])) {
                $lineOverrides[(int) $row['item_id']] = (float) ($row['qty'] ?? 0);
            }
        }

        try {
            $result = $this->service->issueAll($wo, $user, $targetQty, $lineOverrides);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $message = $result['partial']
            ? sprintf(
                'Partial issue: can produce %.4f of %.4f target units (bottleneck %.0f%%). %d shortage(s) detected.',
                $result['producible_qty'],
                $result['target_qty'],
                $result['bottleneck_ratio'] * 100,
                count($result['shortages'])
            )
            : sprintf('Goods issue posted — materials adjusted for %.4f %s.', $result['target_qty'], $wo->unit);

        try {
            broadcast(new DashboardEvent('manufacturing', 'wo_issued', ['wo_no' => $wo->wo_no]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
        }

        return ApiResponse::success([
            'work_order' => $wo->fresh(['items', 'bom', 'workCentre', 'labour', 'overhead']),
            'issue'      => $result,
        ], $message);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $data = $request->validate([
            'actual_qty'              => 'required|numeric|min:0.0001',
            'shortfall_reason'        => ['nullable', Rule::in([WorkOrderService::SHORTFALL_UNUSED, WorkOrderService::SHORTFALL_SPOILED])],
            'byproducts'              => 'nullable|array',
            'byproducts.*.stock_id'   => 'required_with:byproducts|string|exists:items,stock_id',
            'byproducts.*.qty'        => 'required_with:byproducts|numeric|min:0.0001',
            'byproducts.*.unit'       => 'nullable|string|max:30',
            'byproducts.*.loc_code'   => 'nullable|string|max:200|exists:inventory_locations,code',
        ]);
        $user = auth()->user()?->user_id ?? '';
        DB::beginTransaction();
        try {
            $this->service->complete($wo, (float) $data['actual_qty'], $user, $data['shortfall_reason'] ?? null);

            // Register by-products
            foreach ($data['byproducts'] ?? [] as $bp) {
                $bpItem   = DB::table('items')->where('stock_id', $bp['stock_id'])->first();
                $locCode  = $bp['loc_code'] ?? ($wo->output_location_code ?: $wo->location_code);
                $unitCost = (float) ($bpItem?->purchase_cost ?? 0);

                DB::table('stock_movements')->insert([
                    'trans_no'      => $wo->id,
                    'stock_id'      => $bp['stock_id'],
                    'type'          => WorkOrderService::TYPE_WO_RECEIPT,
                    'loc_code'      => $locCode,
                    'tran_date'     => now()->toDateString(),
                    'qty'           => (float) $bp['qty'],
                    'price'         => $unitCost,
                    'standard_cost' => $unitCost,
                    'reference'     => $wo->wo_no,
                    'comments'      => 'By-Product — ' . ($bpItem?->description ?? $bp['stock_id']),
                    'unique_key'    => \Illuminate\Support\Str::uuid()->toString(),
                ]);

                DB::table('work_order_byproducts')->insert([
                    'work_order_id'       => $wo->id,
                    'work_order_stage_id' => null,
                    'stage_code'          => 'completion',
                    'stage_name'          => 'WO Completion',
                    'stock_id'            => $bp['stock_id'],
                    'description'         => $bpItem?->description ?? '',
                    'qty'                 => (float) $bp['qty'],
                    'unit'                => $bp['unit'] ?? ($bpItem?->units ?? ''),
                    'location_code'       => $locCode,
                    'registered_by'       => $user,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), 422);
        }

        try {
            broadcast(new DashboardEvent('manufacturing', 'wo_completed', ['wo_no' => $wo->wo_no]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
        }

        return ApiResponse::success($wo->fresh(['items', 'bom', 'workCentre', 'labour', 'overhead']), 'Work order completed — costs calculated');
    }

    public function settle(int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $user = auth()->user()?->user_id ?? '';
        DB::beginTransaction();
        try {
            $this->service->settle($wo, $user);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), 422);
        }
        return ApiResponse::success($wo->fresh(['items', 'bom', 'workCentre', 'labour', 'overhead']), 'Work order settled — finished goods stock updated');
    }

    // ── Labour entry ──────────────────────────────────────────────────────────
    public function addLabour(Request $request, int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $data = $request->validate([
            'operator_name'  => 'required|string|max:100',
            'role'           => 'nullable|string|max:100',
            'rate_per_hour'  => 'required|numeric|min:0',
            'hours_worked'   => 'required|numeric|min:0.01',
            'work_date'      => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'credit_account' => 'required|string|max:20',
        ]);

        $totalCost = round($data['rate_per_hour'] * $data['hours_worked'], 4);
        $user = auth()->user()?->user_id ?? '';

        $labour = DB::transaction(function () use ($data, $wo, $totalCost, $user) {
            $labour = WorkOrderLabour::create(array_merge($data, [
                'work_order_id' => $wo->id,
                'total_cost'    => $totalCost,
                'created_by'    => $user,
            ]));

            $wo->update(['total_labour_cost' => $wo->labour()->sum('total_cost')]);

            if ($totalCost > 0) {
                $this->service->postCostEntry($wo, $totalCost, $data['credit_account'], "Labour — {$data['operator_name']} ({$wo->wo_no})", $user);
            }

            return $labour;
        });

        return ApiResponse::created($labour, 'Labour entry added and posted to GL');
    }

    // ── Overhead entry ────────────────────────────────────────────────────────
    public function addOverhead(Request $request, int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $data = $request->validate([
            'description'    => 'required|string|max:200',
            'overhead_type'  => 'required|in:variable,fixed',
            'amount'         => 'required|numeric|min:0',
            'date_posted'    => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'credit_account' => 'required|string|max:20',
        ]);

        $user = auth()->user()?->user_id ?? '';

        $overhead = DB::transaction(function () use ($data, $wo, $user) {
            $overhead = WorkOrderOverhead::create(array_merge($data, [
                'work_order_id' => $wo->id,
                'created_by'    => $user,
            ]));

            $wo->update(['total_overhead_cost' => $wo->overhead()->sum('amount')]);

            if ($data['amount'] > 0) {
                $this->service->postCostEntry($wo, (float) $data['amount'], $data['credit_account'], "Overhead — {$data['description']} ({$wo->wo_no})", $user);
            }

            return $overhead;
        });

        return ApiResponse::created($overhead, 'Overhead entry added and posted to GL');
    }

    // ── Cost sheet (for reporting) ─────────────────────────────────────────────
    public function costSheet(int $id): JsonResponse
    {
        $wo = WorkOrder::with(['bom', 'workCentre', 'mfgType', 'items', 'labour', 'overhead', 'byproducts'])->findOrFail($id);

        // Both configurable per BOM (Bom::scrap_pct / target_margin_pct) —
        // fall back to the historical flat values if the WO has no BOM.
        $scrapPct        = $wo->bom?->scrap_pct ?? 0;
        $grossMarginPct  = $wo->bom?->target_margin_pct ?? 35;
        $unitCost        = (float) $wo->unit_cost;
        $actualQty       = (float) ($wo->actual_qty_produced ?: $wo->planned_qty);
        $sellingPrice    = $unitCost > 0 ? round($unitCost / (1 - $grossMarginPct / 100), 2) : 0;

        $perUnit = fn($batch) => $actualQty > 0 ? round((float) $batch / $actualQty, 4) : 0;

        $sheet = [
            'work_order'    => $wo,
            'cost_summary'  => [
                ['label' => 'Direct Materials',             'batch_total' => (float) $wo->total_material_cost, 'per_unit' => $perUnit($wo->total_material_cost)],
                ['label' => 'Direct Labour',                'batch_total' => (float) $wo->total_labour_cost,   'per_unit' => $perUnit($wo->total_labour_cost)],
                ['label' => 'Variable Overhead',             'batch_total' => (float) $wo->total_overhead_cost, 'per_unit' => $perUnit($wo->total_overhead_cost)],
                ['label' => "Scrap / Waste ({$scrapPct}%)",  'batch_total' => (float) $wo->total_scrap_cost,    'per_unit' => $perUnit($wo->total_scrap_cost)],
                ['label' => 'TOTAL COST',                    'batch_total' => (float) $wo->total_cost,          'per_unit' => $unitCost],
            ],
            'pricing'       => [
                'unit_cost'              => $unitCost,
                'target_margin_pct'      => $grossMarginPct,
                'suggested_price_ex_vat' => $sellingPrice,
                'suggested_price_inc_vat'=> round($sellingPrice * 1.16, 2),
                'gross_profit'           => round($sellingPrice - $unitCost, 4),
            ],
        ];

        return ApiResponse::success($sheet, 'Cost sheet retrieved');
    }

    // ── KPIs ─────────────────────────────────────────────────────────────────
    public function kpis(): JsonResponse
    {
        $kpis = [
            'total_orders'       => WorkOrder::count(),
            'draft'              => WorkOrder::where('status', 'draft')->count(),
            'released'           => WorkOrder::where('status', 'released')->count(),
            'in_progress'        => WorkOrder::where('status', 'in_progress')->count(),
            'completed_today'    => WorkOrder::where('status', 'completed')->whereDate('completed_date', today())->count(),
            'overdue'            => WorkOrder::whereIn('status', ['released', 'in_progress'])->where('due_date', '<', today())->count(),
        ];
        return ApiResponse::success($kpis, 'KPIs retrieved');
    }
}
