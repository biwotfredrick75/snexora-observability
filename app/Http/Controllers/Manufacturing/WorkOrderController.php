<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WorkOrder;
use App\Models\WorkOrderLabour;
use App\Models\WorkOrderOverhead;
use App\Services\Manufacturing\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $wo = WorkOrder::with(['bom', 'workCentre', 'items', 'labour', 'overhead'])->findOrFail($id);
        return ApiResponse::success($wo, 'Work order retrieved');
    }

    // ── Create (Draft) ────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_code'        => 'required|string|max:20|exists:items,stock_id',
            'bom_id'              => 'required|integer|exists:boms,id',
            'work_centre_id'      => 'nullable|integer|exists:work_centres,id',
            'planned_qty'         => 'required|numeric|min:0.0001',
            'unit'                => 'nullable|string|max:20',
            'start_date'          => 'required|date',
            'due_date'            => 'required|date|after_or_equal:start_date',
            'location_code'       => 'nullable|string|max:20',
            'output_location_code'=> 'nullable|string|max:20',
            'notes'               => 'nullable|string',
        ]);

        $wo = DB::transaction(function () use ($data, $request) {
            $maxId = WorkOrder::lockForUpdate()->max('id') ?? 0;
            $woNo  = 'WO-' . now()->format('Y') . '-' . str_pad($maxId + 1, 4, '0', STR_PAD_LEFT);

            $item = DB::table('items')->where('stock_id', $data['product_code'])->first();

            $wo = WorkOrder::create([
                'wo_no'               => $woNo,
                'product_code'        => $data['product_code'],
                'product_description' => $item?->description ?? '',
                'bom_id'              => $data['bom_id'],
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
            'work_centre_id'      => 'nullable|integer|exists:work_centres,id',
            'planned_qty'         => 'required|numeric|min:0.0001',
            'unit'                => 'nullable|string|max:20',
            'start_date'          => 'required|date',
            'due_date'            => 'required|date|after_or_equal:start_date',
            'location_code'       => 'nullable|string|max:20',
            'output_location_code'=> 'nullable|string|max:20',
            'notes'               => 'nullable|string',
        ]);

        $item = DB::table('items')->where('stock_id', $data['product_code'])->first();
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

    public function issueAll(int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $user = auth()->user()?->user_id ?? '';
        try {
            $result = $this->service->issueAll($wo, $user);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $message = $result['partial']
            ? sprintf(
                'Partial issue: can produce %.4f of %.4f planned units (bottleneck %.0f%%). %d shortage(s) detected.',
                $result['producible_qty'],
                $result['planned_qty'],
                $result['bottleneck_ratio'] * 100,
                count($result['shortages'])
            )
            : 'Goods issue posted — all materials fully issued to production.';

        return ApiResponse::success([
            'work_order' => $wo->fresh(['items']),
            'issue'      => $result,
        ], $message);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $data = $request->validate(['actual_qty' => 'required|numeric|min:0.0001']);
        $user = auth()->user()?->user_id ?? '';
        DB::beginTransaction();
        try {
            $this->service->complete($wo, (float) $data['actual_qty'], $user);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), 422);
        }
        return ApiResponse::success($wo->fresh(), 'Work order completed — costs calculated');
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
        return ApiResponse::success($wo->fresh(), 'Work order settled — finished goods stock updated');
    }

    // ── Labour entry ──────────────────────────────────────────────────────────
    public function addLabour(Request $request, int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $data = $request->validate([
            'operator_name' => 'required|string|max:100',
            'role'          => 'nullable|string|max:100',
            'rate_per_hour' => 'required|numeric|min:0',
            'hours_worked'  => 'required|numeric|min:0.01',
            'work_date'     => ['required', 'date', new \App\Rules\WithinFiscalYear],
        ]);

        $totalCost = round($data['rate_per_hour'] * $data['hours_worked'], 4);
        $labour = WorkOrderLabour::create(array_merge($data, [
            'work_order_id' => $wo->id,
            'total_cost'    => $totalCost,
            'created_by'    => auth()->user()?->user_id ?? '',
        ]));

        // Refresh labour total on WO
        $wo->update(['total_labour_cost' => $wo->labour()->sum('total_cost')]);

        return ApiResponse::created($labour, 'Labour entry added');
    }

    // ── Overhead entry ────────────────────────────────────────────────────────
    public function addOverhead(Request $request, int $id): JsonResponse
    {
        $wo   = WorkOrder::findOrFail($id);
        $data = $request->validate([
            'description'   => 'required|string|max:200',
            'overhead_type' => 'required|in:variable,fixed',
            'amount'        => 'required|numeric|min:0',
            'date_posted'   => ['required', 'date', new \App\Rules\WithinFiscalYear],
        ]);

        $overhead = WorkOrderOverhead::create(array_merge($data, [
            'work_order_id' => $wo->id,
            'created_by'    => auth()->user()?->user_id ?? '',
        ]));

        // Refresh overhead total on WO
        $wo->update(['total_overhead_cost' => $wo->overhead()->sum('amount')]);

        return ApiResponse::created($overhead, 'Overhead entry added');
    }

    // ── Cost sheet (for reporting) ─────────────────────────────────────────────
    public function costSheet(int $id): JsonResponse
    {
        $wo = WorkOrder::with(['bom', 'workCentre', 'items', 'labour', 'overhead'])->findOrFail($id);

        $grossMarginPct = 35; // default target margin
        $unitCost       = $wo->unit_cost;
        $sellingPrice   = $unitCost > 0 ? round($unitCost / (1 - $grossMarginPct / 100), 2) : 0;

        $sheet = [
            'work_order'    => $wo,
            'cost_summary'  => [
                ['label' => 'Direct Materials',    'batch_total' => $wo->total_material_cost,  'per_unit' => $wo->planned_qty > 0 ? round($wo->total_material_cost  / $wo->planned_qty, 4) : 0],
                ['label' => 'Direct Labour',       'batch_total' => $wo->total_labour_cost,    'per_unit' => $wo->planned_qty > 0 ? round($wo->total_labour_cost    / $wo->planned_qty, 4) : 0],
                ['label' => 'Variable Overhead',   'batch_total' => $wo->total_overhead_cost,  'per_unit' => $wo->planned_qty > 0 ? round($wo->total_overhead_cost  / $wo->planned_qty, 4) : 0],
                ['label' => 'Scrap / Waste (3%)',  'batch_total' => $wo->total_scrap_cost,     'per_unit' => $wo->planned_qty > 0 ? round($wo->total_scrap_cost     / $wo->planned_qty, 4) : 0],
                ['label' => 'TOTAL COST',          'batch_total' => $wo->total_cost,           'per_unit' => $unitCost],
            ],
            'pricing'       => [
                'unit_cost'       => $unitCost,
                'target_margin'   => $grossMarginPct,
                'selling_price'   => $sellingPrice,
                'selling_incl_vat'=> round($sellingPrice * 1.16, 2),
                'gross_profit'    => round($sellingPrice - $unitCost, 4),
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
