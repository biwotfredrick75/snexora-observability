<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ProductionPlan;
use App\Models\ProductionPlanItem;
use App\Models\WorkOrder;
use App\Services\Manufacturing\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductionPlanController extends Controller
{
    public function __construct(private WorkOrderService $service) {}

    /** GET /manufacturing/production-plans */
    public function index(Request $request)
    {
        $query = ProductionPlan::withCount('items');

        if ($request->q) {
            $query->where('plan_no', 'like', "%{$request->q}%");
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }

        $plans = $query->orderByDesc('created_at')->get();
        return ApiResponse::success($plans);
    }

    /** POST /manufacturing/production-plans */
    public function store(Request $request)
    {
        $request->validate([
            'plan_date'               => 'required|date',
            'production_location'     => 'nullable|string|max:100',
            'target_completion_date'  => 'nullable|date|after_or_equal:plan_date',
            'memo'                    => 'nullable|string',
        ]);

        $plan = DB::transaction(function () use ($request) {
            $year = now()->year;
            $seq  = DB::table('production_plans')
                ->whereYear('created_at', $year)
                ->lockForUpdate()
                ->count();
            $planNo = sprintf('PROD-%d-%04d', $year, $seq + 1);

            return ProductionPlan::create([
                'plan_no'                => $planNo,
                'status'                 => 'draft',
                'production_location'    => $request->production_location,
                'plan_date'              => $request->plan_date,
                'target_completion_date' => $request->target_completion_date,
                'memo'                   => $request->memo,
                'created_by'             => Auth::user()?->user_id ?? 'system',
            ]);
        });

        $plan->load('items');
        return ApiResponse::created($plan);
    }

    /** GET /manufacturing/production-plans/{id} */
    public function show($id)
    {
        $plan = ProductionPlan::with('items')->findOrFail($id);
        return ApiResponse::success($plan);
    }

    /** PUT /manufacturing/production-plans/{id} */
    public function update(Request $request, $id)
    {
        $plan = ProductionPlan::findOrFail($id);

        if ($plan->status !== 'draft') {
            return ApiResponse::validationError(['error' => 'Only draft plans can be edited.']);
        }

        $request->validate([
            'plan_date'              => 'required|date',
            'production_location'    => 'nullable|string|max:100',
            'target_completion_date' => 'nullable|date',
            'memo'                   => 'nullable|string',
        ]);

        $plan->update($request->only(['production_location', 'plan_date', 'target_completion_date', 'memo']));
        $plan->load('items');
        return ApiResponse::updated($plan);
    }

    /** DELETE /manufacturing/production-plans/{id} */
    public function destroy($id)
    {
        $plan = ProductionPlan::findOrFail($id);

        if ($plan->status !== 'draft') {
            return ApiResponse::validationError(['error' => 'Only draft plans can be deleted.']);
        }

        $plan->delete();
        return ApiResponse::deleted('Production plan deleted.');
    }

    /** POST /manufacturing/production-plans/{id}/items */
    public function addItem(Request $request, $id)
    {
        $plan = ProductionPlan::findOrFail($id);

        if ($plan->status !== 'draft') {
            return ApiResponse::validationError(['error' => 'Cannot add items to a non-draft plan.']);
        }

        $request->validate([
            'stock_id'   => 'required|string|max:50',
            'planned_qty'=> 'required|numeric|min:0.001',
        ]);

        // Get current stock on hand from stock_movements
        $stockQty = (float) DB::table('stock_movements')
            ->where('stock_id', $request->stock_id)
            ->sum('qty');

        // Get description from items table
        $itemRow = DB::table('items')
            ->where('stock_id', $request->stock_id)
            ->first(['description']);

        ProductionPlanItem::create([
            'production_plan_id' => $plan->id,
            'stock_id'           => $request->stock_id,
            'description'        => $request->description ?? ($itemRow?->description ?? $request->stock_id),
            'current_stock'      => $stockQty,
            'planned_qty'        => $request->planned_qty,
        ]);

        $plan->load('items');
        return ApiResponse::created($plan);
    }

    /** DELETE /manufacturing/production-plans/{id}/items/{itemId} */
    public function removeItem($id, $itemId)
    {
        $plan = ProductionPlan::findOrFail($id);

        if ($plan->status !== 'draft') {
            return ApiResponse::validationError(['error' => 'Cannot remove items from a non-draft plan.']);
        }

        ProductionPlanItem::where('id', $itemId)
            ->where('production_plan_id', $id)
            ->delete();

        $plan->load('items');
        return ApiResponse::success($plan);
    }

    /** POST /manufacturing/production-plans/{id}/submit */
    public function submit($id)
    {
        $plan = ProductionPlan::with('items')->findOrFail($id);

        if ($plan->status !== 'draft') {
            return ApiResponse::validationError(['error' => 'Only draft plans can be submitted.']);
        }
        if ($plan->items->isEmpty()) {
            return ApiResponse::validationError(['error' => 'Add at least one item before submitting.']);
        }

        $plan->update([
            'status'       => 'submitted',
            'submitted_by' => Auth::user()?->user_id ?? 'system',
            'submitted_at' => now(),
        ]);

        return ApiResponse::updated($plan);
    }

    /**
     * POST /manufacturing/production-plans/{id}/execute
     * Body: { items: [ { id: <itemId>, actual_qty: <float> }, … ] }
     *
     * For each item, creates a real WorkOrder against its active BOM and runs it
     * through the full release → issue-all → complete → settle lifecycle, so
     * this button actually consumes raw materials and receipts finished goods —
     * it used to only increment production_plan_items.actual_qty with no
     * stock_movements at all.
     *
     * - Transitions approved → in_progress on first successful item
     * - Adds the *actually produced* qty to each item (may be less than
     *   requested if materials run short — issueAll() caps production at what
     *   stock allows rather than failing outright)
     * - Auto-completes plan when all items actual_qty >= planned_qty
     */
    public function execute(Request $request, $id)
    {
        $plan = ProductionPlan::with('items')->findOrFail($id);

        if (!in_array($plan->status, ['approved', 'in_progress'])) {
            return ApiResponse::validationError(['error' => 'Only approved or in-progress plans can be executed.']);
        }

        $request->validate([
            'items'             => 'required|array|min:1',
            'items.*.id'        => 'required|integer',
            'items.*.actual_qty'=> 'required|numeric|min:0.001',
        ]);

        $user    = Auth::user()?->user_id ?? 'system';
        $results = [];

        foreach ($request->items as $row) {
            $item = $plan->items->firstWhere('id', $row['id']);
            if (!$item) continue;

            $requestedQty = (float) $row['actual_qty'];

            try {
                $outcome = DB::transaction(function () use ($plan, $item, $requestedQty, $user) {
                    $bom = DB::table('boms')
                        ->where('product_code', $item->stock_id)
                        ->where('is_active', 1)
                        ->orderByDesc('version')
                        ->first();

                    if (!$bom) {
                        throw new \RuntimeException("No active BOM for {$item->stock_id} — cannot produce.");
                    }

                    $woItem = DB::table('items')->where('stock_id', $item->stock_id)->first();
                    $today  = now()->toDateString();

                    $wo = WorkOrder::create([
                        'production_plan_id'      => $plan->id,
                        'production_plan_item_id' => $item->id,
                        'wo_no'                   => 'TEMP',
                        'product_code'            => $item->stock_id,
                        'product_description'     => $item->description ?? ($woItem?->description ?? $item->stock_id),
                        'bom_id'                  => $bom->id,
                        'mfg_type_id'             => $bom->mfg_type_id,
                        'planned_qty'             => $requestedQty,
                        'unit'                    => $woItem?->units ?? '',
                        'start_date'              => $plan->plan_date ?? $today,
                        'due_date'                => $plan->target_completion_date ?? $plan->plan_date ?? $today,
                        'location_code'           => $plan->production_location ?? '',
                        'output_location_code'    => $plan->production_location ?? '',
                        'status'                  => WorkOrderService::STATUS_DRAFT,
                        'created_by'              => $user,
                    ]);
                    $wo->update(['wo_no' => 'WO-' . now()->format('Y') . '-' . str_pad($wo->id, 4, '0', STR_PAD_LEFT)]);

                    $this->service->release($wo, $user);
                    $issue = $this->service->issueAll($wo, $user, $requestedQty);

                    // issueAll() caps producible_qty at requestedQty (bottleneck <= 1),
                    // so this never exceeds what was asked for.
                    $producedQty = $issue['producible_qty'];
                    $this->service->complete($wo, $producedQty, $user);
                    $this->service->settle($wo, $user);

                    return [
                        'qty'       => $producedQty,
                        'wo_no'     => $wo->wo_no,
                        'partial'   => $issue['partial'],
                        'shortages' => $issue['shortages'],
                    ];
                });

                $item->increment('actual_qty', $outcome['qty']);
                $results[] = [
                    'item_id'      => $item->id,
                    'stock_id'     => $item->stock_id,
                    'success'      => true,
                    'wo_no'        => $outcome['wo_no'],
                    'qty_produced' => $outcome['qty'],
                    'partial'      => $outcome['partial'],
                    'shortages'    => $outcome['shortages'],
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'item_id'  => $item->id,
                    'stock_id' => $item->stock_id,
                    'success'  => false,
                    'error'    => $e->getMessage(),
                ];
            }
        }

        $anySuccess = collect($results)->contains('success', true);
        if ($plan->status === 'approved' && $anySuccess) {
            $plan->update(['status' => 'in_progress']);
        }

        // Auto-complete when all items fully produced
        $plan->load('items');
        $allDone = $plan->items->every(fn ($i) => $i->actual_qty >= $i->planned_qty);
        if ($allDone) {
            $plan->update(['status' => 'completed']);
        }

        $anyFailed = collect($results)->contains('success', false);
        $message = $anyFailed
            ? 'Production recorded with errors on some items — see results.'
            : ($plan->status === 'completed' ? 'Plan completed — all items produced.' : 'Production recorded — work orders created and materials issued.');

        return ApiResponse::success([
            'plan'    => $plan->fresh('items'),
            'results' => $results,
        ], $message);
    }

    /**
     * GET /manufacturing/production-plans/{id}/approval-detail
     * Returns plan + BOM components expanded per item for the approval page.
     */
    public function approvalDetail($id)
    {
        $plan = ProductionPlan::with('items')->findOrFail($id);

        // For each plan item, find its active BOM and expand components
        $itemsWithBom = $plan->items->map(function ($item) {
            $bom = DB::table('boms')
                ->where('product_code', $item->stock_id)
                ->where('is_active', 1)
                ->orderByDesc('version')
                ->first();

            $components = [];
            if ($bom) {
                $components = DB::table('bom_items')
                    ->where('bom_id', $bom->id)
                    ->orderBy('sort_order')
                    ->get(['component_code', 'description', 'qty_required', 'unit'])
                    ->map(function ($c) use ($item) {
                        $loc = '';

                        return [
                            'component_code' => $c->component_code,
                            'description'    => $c->description,
                            'qty_per_unit'   => (float) $c->qty_required,
                            'total_required' => round((float) $c->qty_required * (float) $item->planned_qty, 6),
                            'unit'           => $c->unit,
                            'location'       => $loc,
                        ];
                    })->toArray();
            }

            return [
                'id'           => $item->id,
                'stock_id'     => $item->stock_id,
                'description'  => $item->description,
                'planned_qty'  => (float) $item->planned_qty,
                'bom'          => $bom ? ['id' => $bom->id, 'bom_no' => $bom->bom_no] : null,
                'components'   => $components,
            ];
        });

        return ApiResponse::success([
            'plan'  => $plan,
            'items' => $itemsWithBom,
        ]);
    }

    /** POST /manufacturing/production-plans/{id}/supervisor-approve */
    public function supervisorApprove(Request $request, $id)
    {
        $plan = ProductionPlan::with('items')->findOrFail($id);

        if (!in_array($plan->status, ['draft', 'submitted'])) {
            return ApiResponse::validationError(['error' => 'Only draft or submitted plans can be supervisor-approved.']);
        }
        if ($plan->items->isEmpty()) {
            return ApiResponse::validationError(['error' => 'Add at least one item before approving.']);
        }

        $plan->update([
            'status'                  => 'supervisor_approved',
            'supervisor_approved_by'  => Auth::user()?->user_id ?? 'system',
            'supervisor_approved_at'  => now(),
            'approve_comments'        => $request->comments ?? null,
        ]);

        return ApiResponse::updated($plan->fresh('items'));
    }

    /** POST /manufacturing/production-plans/{id}/approve  (HOD final approval) */
    public function approve(Request $request, $id)
    {
        $plan = ProductionPlan::with('items')->findOrFail($id);

        if (!in_array($plan->status, ['draft', 'submitted', 'supervisor_approved'])) {
            return ApiResponse::validationError(['error' => 'Plan must be submitted or supervisor-approved before HOD approval.']);
        }
        if ($plan->items->isEmpty()) {
            return ApiResponse::validationError(['error' => 'Add at least one item before approving.']);
        }

        $plan->update([
            'status'          => 'approved',
            'approved_by'     => Auth::user()?->user_id ?? 'system',
            'approved_at'     => now(),
            'approve_comments'=> $request->comments ?? $plan->approve_comments,
        ]);

        return ApiResponse::updated($plan->fresh('items'));
    }

    /** POST /manufacturing/production-plans/{id}/reject */
    public function reject(Request $request, $id)
    {
        $plan = ProductionPlan::findOrFail($id);

        if (!in_array($plan->status, ['draft', 'submitted', 'supervisor_approved'])) {
            return ApiResponse::validationError(['error' => 'This plan cannot be rejected in its current state.']);
        }

        $plan->update([
            'status'          => 'rejected',
            'rejected_by'     => Auth::user()?->user_id ?? 'system',
            'rejected_at'     => now(),
            'reject_comments' => $request->comments ?? null,
        ]);

        return ApiResponse::updated($plan->fresh('items'));
    }

    /** GET /manufacturing/production-plans/{id}/execute-detail */
    public function executeDetail($id)
    {
        $plan = ProductionPlan::with('items')->findOrFail($id);

        // Work orders created for this plan
        $workOrders = WorkOrder::where('production_plan_id', $id)
            ->orderByDesc('created_at')
            ->get(['id', 'wo_no', 'product_code', 'product_description',
                   'planned_qty', 'actual_qty_produced', 'start_date', 'status',
                   'production_plan_item_id']);

        // Stock requisitions linked to this plan
        $requisitions = DB::table('stock_requisitions')
            ->where('production_plan_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success([
            'plan'         => $plan,
            'work_orders'  => $workOrders,
            'requisitions' => $requisitions,
        ]);
    }

    /** POST /manufacturing/production-plans/{id}/items/{itemId}/create-wo */
    public function createItemWorkOrder(Request $request, $id, $itemId)
    {
        $plan = ProductionPlan::findOrFail($id);
        $item = ProductionPlanItem::where('id', $itemId)
            ->where('production_plan_id', $id)
            ->firstOrFail();

        if (!in_array($plan->status, ['approved', 'in_progress'])) {
            return ApiResponse::validationError(['error' => 'Plan must be approved or in-progress to create work orders.']);
        }

        $request->validate([
            'bom_id'        => 'required|integer|exists:boms,id',
            'start_date'    => 'required|date',
            'due_date'      => 'required|date|after_or_equal:start_date',
            'work_centre_id'=> 'nullable|integer|exists:work_centres,id',
        ]);

        $openWo = WorkOrder::where('production_plan_item_id', $item->id)
            ->whereNotIn('status', [WorkOrderService::STATUS_CLOSED])
            ->first();
        if ($openWo) {
            return ApiResponse::validationError([
                'error' => "Work order {$openWo->wo_no} ({$openWo->status}) is still open for this item. Close it before creating a new one.",
            ]);
        }

        $remaining = max(0, $item->planned_qty - $item->actual_qty);

        $wo = DB::transaction(function () use ($request, $plan, $item, $remaining) {
            $woItem = DB::table('items')->where('stock_id', $item->stock_id)->first();

            // Inherit mfg_type_id: request override → BOM default
            $mfgTypeId = $request->filled('mfg_type_id')
                ? (int) $request->mfg_type_id
                : DB::table('boms')->where('id', $request->bom_id)->value('mfg_type_id');

            $wo = WorkOrder::create([
                'production_plan_id'      => $plan->id,
                'production_plan_item_id' => $item->id,
                'wo_no'                   => 'TEMP',
                'product_code'            => $item->stock_id,
                'product_description'     => $item->description ?? ($woItem?->description ?? $item->stock_id),
                'bom_id'                  => $request->bom_id,
                'mfg_type_id'             => $mfgTypeId,
                'work_centre_id'          => $request->work_centre_id ?? null,
                'planned_qty'             => $remaining > 0 ? $remaining : $item->planned_qty,
                'unit'                    => $woItem?->units ?? '',
                'start_date'              => $request->start_date,
                'due_date'                => $request->due_date,
                'location_code'           => $plan->production_location ?? '',
                'output_location_code'    => $plan->production_location ?? '',
                'status'                  => WorkOrderService::STATUS_DRAFT,
                'created_by'              => Auth::user()?->user_id ?? 'system',
            ]);

            $wo->update(['wo_no' => 'WO-' . now()->format('Y') . '-' . str_pad($wo->id, 4, '0', STR_PAD_LEFT)]);

            // Transition plan to in_progress if still approved
            if ($plan->status === 'approved') {
                $plan->update(['status' => 'in_progress']);
            }

            return $wo->fresh();
        });

        return ApiResponse::created($wo, "Work order {$wo->wo_no} created");
    }

    /** GET /manufacturing/production-locations */
    public function locations()
    {
        $locations = DB::table('inventory_locations')
            ->where('inactive', 0)
            ->orderBy('name')
            ->get(['code', 'name', 'type']);

        return ApiResponse::success($locations);
    }

    /** GET /manufacturing/items/stock-on-hand?stock_id=XXX&location_code=YYY */
    public function stockOnHand(Request $request)
    {
        $stockId  = $request->stock_id;
        $location = $request->location_code;

        $query = DB::table('stock_movements')->where('stock_id', $stockId);
        if ($location) {
            $query->where('loc_code', $location);
        }

        $qty = (float) $query->sum('qty');

        return ApiResponse::success([
            'stock_id' => $stockId,
            'quantity' => $qty,
        ]);
    }
}
