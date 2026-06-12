<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ManufacturingReportController extends Controller
{
    // ── Form data for filter dropdowns ────────────────────────────────────────
    public function formData(): JsonResponse
    {
        $workCentres = DB::table('work_centres')
            ->where('inactive', false)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $products = DB::table('work_orders')
            ->select('product_code', 'product_description')
            ->distinct()
            ->orderBy('product_description')
            ->get();

        $mfgTypes = DB::table('manufacturing_types')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return ApiResponse::success([
            'work_centres' => $workCentres,
            'products'     => $products,
            'mfg_types'    => $mfgTypes,
            'statuses'     => ['draft', 'released', 'in_progress', 'completed', 'closed'],
        ], 'Form data loaded');
    }

    // ── 1. Production Order Report ────────────────────────────────────────────
    public function productionOrders(Request $request): JsonResponse
    {
        $query = DB::table('work_orders as wo')
            ->leftJoin('work_centres as wc', 'wo.work_centre_id', '=', 'wc.id')
            ->select([
                'wo.id', 'wo.wo_no', 'wo.product_code', 'wo.product_description',
                'wo.planned_qty', 'wo.actual_qty_produced', 'wo.unit',
                'wo.start_date', 'wo.due_date', 'wo.completed_date',
                'wo.status', 'wo.total_cost', 'wo.unit_cost',
                'wc.name as work_centre',
                DB::raw('DATEDIFF(COALESCE(wo.completed_date, CURDATE()), wo.start_date) as lead_days'),
                DB::raw('CASE WHEN wo.planned_qty > 0 THEN ROUND(wo.actual_qty_produced / wo.planned_qty * 100, 1) ELSE 0 END as completion_pct'),
                DB::raw('CASE WHEN wo.due_date < CURDATE() AND wo.status NOT IN (\'completed\',\'closed\') THEN 1 ELSE 0 END as is_overdue'),
            ]);

        $this->applyDateFilters($query, $request, 'wo.start_date');
        if ($request->filled('status'))          $query->where('wo.status', $request->status);
        if ($request->filled('work_centre_id'))  $query->where('wo.work_centre_id', $request->work_centre_id);
        if ($request->filled('product_code'))    $query->where('wo.product_code', $request->product_code);

        $query->orderByDesc('wo.start_date');
        $rows = $query->get();

        $totals = [
            'total_orders'   => $rows->count(),
            'total_planned'  => $rows->sum('planned_qty'),
            'total_actual'   => $rows->sum('actual_qty_produced'),
            'total_cost'     => $rows->sum('total_cost'),
            'overdue'        => $rows->sum('is_overdue'),
            'avg_completion' => $rows->count() ? round($rows->avg('completion_pct'), 1) : 0,
        ];

        $byStatus = $rows->groupBy('status')->map->count()->toArray();

        return ApiResponse::success(compact('rows', 'totals', 'byStatus'), 'Production orders report generated');
    }

    // ── 2. Efficiency Report ──────────────────────────────────────────────────
    public function efficiency(Request $request): JsonResponse
    {
        $groupBy = $request->get('group_by', 'work_centre');

        // Only select non-aggregated columns that belong to the active grouping
        $groupCols = $groupBy === 'product'
            ? ['wo.product_code', 'wo.product_description']
            : ['wc.name as work_centre'];

        $query = DB::table('work_orders as wo')
            ->leftJoin('work_centres as wc', 'wo.work_centre_id', '=', 'wc.id')
            ->leftJoin(
                DB::raw('(SELECT work_order_id, SUM(hours_worked) as total_hours FROM work_order_labour GROUP BY work_order_id) lsub'),
                'lsub.work_order_id', '=', 'wo.id'
            )
            ->whereIn('wo.status', ['completed', 'closed'])
            ->select(array_merge($groupCols, [
                DB::raw('COUNT(wo.id) as order_count'),
                DB::raw('SUM(wo.planned_qty) as total_planned'),
                DB::raw('SUM(wo.actual_qty_produced) as total_actual'),
                DB::raw('ROUND(SUM(wo.actual_qty_produced) / NULLIF(SUM(wo.planned_qty),0) * 100, 1) as output_efficiency'),
                DB::raw('SUM(COALESCE(lsub.total_hours,0)) as total_hours'),
                DB::raw('ROUND(SUM(wo.actual_qty_produced) / NULLIF(SUM(COALESCE(lsub.total_hours,0)),0), 2) as units_per_hour'),
                DB::raw('AVG(DATEDIFF(COALESCE(wo.completed_date, wo.due_date), wo.start_date)) as avg_lead_days'),
            ]));

        $this->applyDateFilters($query, $request, 'wo.start_date');
        if ($request->filled('work_centre_id')) $query->where('wo.work_centre_id', $request->work_centre_id);
        if ($request->filled('product_code'))   $query->where('wo.product_code', $request->product_code);

        if ($groupBy === 'product')  $query->groupBy('wo.product_code', 'wo.product_description');
        else                         $query->groupBy('wo.work_centre_id', 'wc.name');

        $rows = $query->orderByDesc('total_actual')->get();

        $totals = [
            'total_orders'      => $rows->sum('order_count'),
            'total_planned'     => $rows->sum('total_planned'),
            'total_actual'      => $rows->sum('total_actual'),
            'avg_efficiency'    => $rows->count() ? round($rows->avg('output_efficiency'), 1) : 0,
            'total_hours'       => round($rows->sum('total_hours'), 2),
        ];

        return ApiResponse::success(compact('rows', 'totals'), 'Efficiency report generated');
    }

    // ── 3. QC / Stage Failure Report ─────────────────────────────────────────
    public function qcFailure(Request $request): JsonResponse
    {
        $query = DB::table('work_order_stages as wos')
            ->join('work_orders as wo', 'wo.id', '=', 'wos.work_order_id')
            ->leftJoin('work_centres as wc', 'wo.work_centre_id', '=', 'wc.id')
            ->select([
                'wo.wo_no', 'wo.product_code', 'wo.product_description',
                'wc.name as work_centre',
                'wos.stage_code', 'wos.stage_name', 'wos.status as stage_status',
                'wos.qa_data', 'wos.notes as stage_notes',
                'wo.start_date', 'wo.status as wo_status',
                'wos.completed_by', 'wos.completed_at',
            ])
            ->where(function ($q) {
                $q->where('wos.status', 'skipped')
                  ->orWhereNotNull('wos.qa_data');
            });

        $this->applyDateFilters($query, $request, 'wo.start_date');
        if ($request->filled('work_centre_id')) $query->where('wo.work_centre_id', $request->work_centre_id);
        if ($request->filled('product_code'))   $query->where('wo.product_code', $request->product_code);
        if ($request->filled('stage_status'))   $query->where('wos.status', $request->stage_status);

        $query->orderByDesc('wo.start_date');
        $rows = $query->get()->map(function ($r) {
            $qa = $r->qa_data ? json_decode($r->qa_data, true) : null;
            $r->qa_parsed   = $qa;
            $r->has_failure = $qa && (isset($qa['pass']) ? !$qa['pass'] : false);
            return $r;
        });

        $totals = [
            'total_events'   => $rows->count(),
            'skipped_stages' => $rows->where('stage_status', 'skipped')->count(),
            'qa_recorded'    => $rows->whereNotNull('qa_parsed')->count(),
            'qa_failures'    => $rows->where('has_failure', true)->count(),
        ];

        $byStage = $rows->groupBy('stage_name')->map->count()->toArray();

        return ApiResponse::success(compact('rows', 'totals', 'byStage'), 'QC failure report generated');
    }

    // ── 4. BOM Utilisation Report ─────────────────────────────────────────────
    public function bomUtilisation(Request $request): JsonResponse
    {
        $groupBy = $request->get('group_by', 'component');

        $groupCols = $groupBy === 'product'
            ? ['wo.product_code', 'wo.product_description']
            : ['woi.component_code', 'woi.description as component_desc', 'woi.unit'];

        $query = DB::table('work_order_items as woi')
            ->join('work_orders as wo', 'wo.id', '=', 'woi.work_order_id')
            ->leftJoin('work_centres as wc', 'wo.work_centre_id', '=', 'wc.id')
            ->whereIn('wo.status', ['completed', 'closed', 'in_progress'])
            ->select(array_merge($groupCols, [
                DB::raw('SUM(woi.qty_required) as total_required'),
                DB::raw('SUM(woi.qty_issued) as total_issued'),
                DB::raw('SUM(woi.qty_required) - SUM(woi.qty_issued) as variance'),
                DB::raw('ROUND((SUM(woi.qty_issued) / NULLIF(SUM(woi.qty_required),0)) * 100, 1) as utilisation_pct'),
                DB::raw('SUM(woi.line_total) as total_cost'),
            ]));

        $this->applyDateFilters($query, $request, 'wo.start_date');
        if ($request->filled('work_centre_id')) $query->where('wo.work_centre_id', $request->work_centre_id);
        if ($request->filled('product_code'))   $query->where('wo.product_code', $request->product_code);

        if ($groupBy === 'product') {
            $query->groupBy('wo.product_code', 'wo.product_description');
        } else {
            $query->groupBy('woi.component_code', 'woi.description', 'woi.unit');
        }

        $rows = $query->orderByDesc('total_cost')->get();

        $totals = [
            'total_components' => $rows->count(),
            'total_required'   => $rows->sum('total_required'),
            'total_issued'     => $rows->sum('total_issued'),
            'total_variance'   => $rows->sum('variance'),
            'total_cost'       => $rows->sum('total_cost'),
            'avg_utilisation'  => $rows->count() ? round($rows->avg('utilisation_pct'), 1) : 0,
        ];

        return ApiResponse::success(compact('rows', 'totals'), 'BOM utilisation report generated');
    }

    // ── 5. Output Summary ─────────────────────────────────────────────────────
    public function outputSummary(Request $request): JsonResponse
    {
        $groupBy = $request->get('group_by', 'month');

        $periodExpr = match ($groupBy) {
            'day'   => "DATE_FORMAT(wo.completed_date, '%Y-%m-%d')",
            'week'  => "CONCAT(YEAR(wo.completed_date),'-W', LPAD(WEEK(wo.completed_date,1),2,'0'))",
            'year'  => "YEAR(wo.completed_date)",
            default => "DATE_FORMAT(wo.completed_date, '%Y-%m')",
        };

        $breakdown = $request->get('breakdown', 'period');

        // Only select product columns when grouping by product — avoids ONLY_FULL_GROUP_BY errors
        $productCols = $breakdown === 'product'
            ? ['wo.product_code', 'wo.product_description', 'wo.unit']
            : [];

        $query = DB::table('work_orders as wo')
            ->whereIn('wo.status', ['completed', 'closed'])
            ->whereNotNull('wo.completed_date')
            ->select(array_merge(
                [DB::raw("$periodExpr as period")],
                $productCols,
                [
                    DB::raw('COUNT(wo.id) as order_count'),
                    DB::raw('SUM(wo.planned_qty) as total_planned'),
                    DB::raw('SUM(wo.actual_qty_produced) as total_output'),
                    DB::raw('ROUND(SUM(wo.actual_qty_produced) / NULLIF(SUM(wo.planned_qty),0) * 100, 1) as yield_pct'),
                    DB::raw('SUM(wo.total_cost) as total_cost'),
                    DB::raw('AVG(wo.unit_cost) as avg_unit_cost'),
                ]
            ));

        $this->applyDateFilters($query, $request, 'wo.completed_date');
        if ($request->filled('product_code')) $query->where('wo.product_code', $request->product_code);

        if ($breakdown === 'product') {
            $query->groupBy('wo.product_code', 'wo.product_description', 'wo.unit', DB::raw($periodExpr));
        } else {
            $query->groupBy(DB::raw($periodExpr));
        }

        $rows = $query->orderBy('period')->get();

        $totals = [
            'total_orders'  => $rows->sum('order_count'),
            'total_planned' => $rows->sum('total_planned'),
            'total_output'  => $rows->sum('total_output'),
            'avg_yield'     => $rows->count() ? round($rows->avg('yield_pct'), 1) : 0,
            'total_cost'    => $rows->sum('total_cost'),
        ];

        return ApiResponse::success(compact('rows', 'totals'), 'Output summary generated');
    }

    // ── 6. Machine / Work Centre Downtime Report ──────────────────────────────
    public function machineDowntime(Request $request): JsonResponse
    {
        // Derive "downtime" from overdue/delayed work orders per work centre
        $query = DB::table('work_orders as wo')
            ->join('work_centres as wc', 'wo.work_centre_id', '=', 'wc.id')
            ->select([
                'wc.id as work_centre_id', 'wc.name as work_centre', 'wc.code',
                DB::raw('COUNT(wo.id) as total_orders'),
                DB::raw('SUM(CASE WHEN wo.status IN (\'completed\',\'closed\') THEN 1 ELSE 0 END) as completed_orders'),
                DB::raw('SUM(CASE WHEN wo.due_date < CURDATE() AND wo.status NOT IN (\'completed\',\'closed\') THEN 1 ELSE 0 END) as overdue_orders'),
                DB::raw('SUM(CASE WHEN wo.completed_date > wo.due_date THEN 1 ELSE 0 END) as late_completions'),
                DB::raw('AVG(CASE WHEN wo.completed_date IS NOT NULL AND wo.completed_date > wo.due_date THEN DATEDIFF(wo.completed_date, wo.due_date) ELSE 0 END) as avg_delay_days'),
                DB::raw('SUM(CASE WHEN wo.completed_date > wo.due_date THEN DATEDIFF(wo.completed_date, wo.due_date) ELSE 0 END) as total_delay_days'),
                DB::raw('ROUND(SUM(CASE WHEN wo.status IN (\'completed\',\'closed\') THEN 1 ELSE 0 END) / NULLIF(COUNT(wo.id),0) * 100, 1) as on_time_pct'),
            ])
            ->whereNotNull('wo.work_centre_id');

        $this->applyDateFilters($query, $request, 'wo.start_date');
        if ($request->filled('work_centre_id')) $query->where('wo.work_centre_id', $request->work_centre_id);

        $query->groupBy('wc.id', 'wc.name', 'wc.code')
              ->orderByDesc('total_delay_days');

        $rows = $query->get();

        // Stage-level timing gaps per work centre
        $stageDelays = DB::table('work_order_stages as wos')
            ->join('work_orders as wo', 'wo.id', '=', 'wos.work_order_id')
            ->whereIn('wos.status', ['completed', 'skipped'])
            ->whereNotNull('wos.started_at')
            ->whereNotNull('wos.completed_at')
            ->select([
                'wo.work_centre_id',
                'wos.stage_name',
                DB::raw('COUNT(*) as stage_count'),
                DB::raw('AVG(TIMESTAMPDIFF(MINUTE, wos.started_at, wos.completed_at)) as avg_duration_min'),
                DB::raw('SUM(CASE WHEN wos.status=\'skipped\' THEN 1 ELSE 0 END) as skipped_count'),
            ])
            ->groupBy('wo.work_centre_id', 'wos.stage_name')
            ->get();

        $totals = [
            'total_work_centres' => $rows->count(),
            'total_orders'       => $rows->sum('total_orders'),
            'overdue_orders'     => $rows->sum('overdue_orders'),
            'total_delay_days'   => $rows->sum('total_delay_days'),
            'avg_on_time_pct'    => $rows->count() ? round($rows->avg('on_time_pct'), 1) : 0,
        ];

        return ApiResponse::success(compact('rows', 'totals', 'stageDelays'), 'Machine downtime report generated');
    }

    // ── 7. Cost of Production ─────────────────────────────────────────────────
    public function costOfProduction(Request $request): JsonResponse
    {
        $groupBy = $request->get('group_by', 'product');

        $selectProduct = match($groupBy) {
            'work_centre' => ['wc.name as group_label'],
            'month'       => [DB::raw("DATE_FORMAT(wo.start_date,'%Y-%m') as group_label")],
            default       => ['wo.product_description as group_label', 'wo.product_code'],
        };

        $query = DB::table('work_orders as wo')
            ->leftJoin('work_centres as wc', 'wo.work_centre_id', '=', 'wc.id')
            ->select(array_merge($selectProduct, [
                DB::raw('COUNT(wo.id) as order_count'),
                DB::raw('SUM(wo.actual_qty_produced) as total_qty'),
                DB::raw('SUM(wo.total_material_cost) as material_cost'),
                DB::raw('SUM(wo.total_labour_cost) as labour_cost'),
                DB::raw('SUM(wo.total_overhead_cost) as overhead_cost'),
                DB::raw('SUM(wo.total_scrap_cost) as scrap_cost'),
                DB::raw('SUM(wo.total_cost) as total_cost'),
                DB::raw('AVG(wo.unit_cost) as avg_unit_cost'),
                DB::raw('ROUND(SUM(wo.total_material_cost)/NULLIF(SUM(wo.total_cost),0)*100,1) as material_pct'),
                DB::raw('ROUND(SUM(wo.total_labour_cost)/NULLIF(SUM(wo.total_cost),0)*100,1) as labour_pct'),
                DB::raw('ROUND(SUM(wo.total_overhead_cost)/NULLIF(SUM(wo.total_cost),0)*100,1) as overhead_pct'),
            ]));

        $this->applyDateFilters($query, $request, 'wo.start_date');
        if ($request->filled('work_centre_id')) $query->where('wo.work_centre_id', $request->work_centre_id);
        if ($request->filled('product_code'))   $query->where('wo.product_code', $request->product_code);
        if ($request->filled('status'))         $query->where('wo.status', $request->status);

        match($groupBy) {
            'work_centre' => $query->groupBy('wc.id', 'wc.name'),
            'month'       => $query->groupBy(DB::raw("DATE_FORMAT(wo.start_date,'%Y-%m')")),
            default       => $query->groupBy('wo.product_code', 'wo.product_description'),
        };

        $rows = $query->orderByDesc('total_cost')->get();

        $totals = [
            'total_orders'    => $rows->sum('order_count'),
            'total_qty'       => $rows->sum('total_qty'),
            'material_cost'   => $rows->sum('material_cost'),
            'labour_cost'     => $rows->sum('labour_cost'),
            'overhead_cost'   => $rows->sum('overhead_cost'),
            'scrap_cost'      => $rows->sum('scrap_cost'),
            'total_cost'      => $rows->sum('total_cost'),
        ];

        return ApiResponse::success(compact('rows', 'totals'), 'Cost of production report generated');
    }

    // ── 8. Work Order Variance Report ─────────────────────────────────────────
    public function woVariance(Request $request): JsonResponse
    {
        $query = DB::table('work_orders as wo')
            ->leftJoin('work_centres as wc', 'wo.work_centre_id', '=', 'wc.id')
            ->leftJoin('boms as b', 'wo.bom_id', '=', 'b.id')
            ->whereIn('wo.status', ['completed', 'closed'])
            ->select([
                'wo.wo_no', 'wo.product_code', 'wo.product_description',
                'wc.name as work_centre',
                'wo.planned_qty', 'wo.actual_qty_produced',
                DB::raw('wo.actual_qty_produced - wo.planned_qty as qty_variance'),
                DB::raw('ROUND((wo.actual_qty_produced - wo.planned_qty) / NULLIF(wo.planned_qty,0) * 100, 1) as qty_variance_pct'),
                'wo.total_material_cost', 'wo.total_labour_cost', 'wo.total_overhead_cost',
                'wo.total_cost', 'wo.unit_cost',
                'wo.start_date', 'wo.due_date', 'wo.completed_date',
                DB::raw('DATEDIFF(COALESCE(wo.completed_date, CURDATE()), wo.due_date) as days_variance'),
            ]);

        $this->applyDateFilters($query, $request, 'wo.start_date');
        if ($request->filled('work_centre_id')) $query->where('wo.work_centre_id', $request->work_centre_id);
        if ($request->filled('product_code'))   $query->where('wo.product_code', $request->product_code);

        $query->orderByDesc('wo.completed_date');
        $rows = $query->get();

        $totals = [
            'total_orders'      => $rows->count(),
            'total_planned_qty' => $rows->sum('planned_qty'),
            'total_actual_qty'  => $rows->sum('actual_qty_produced'),
            'total_qty_variance'=> $rows->sum('qty_variance'),
            'total_cost'        => $rows->sum('total_cost'),
            'on_time_count'     => $rows->where('days_variance', '<=', 0)->count(),
            'late_count'        => $rows->where('days_variance', '>', 0)->count(),
        ];

        return ApiResponse::success(compact('rows', 'totals'), 'WO variance report generated');
    }

    // ── 9. Production Plan Status Report ─────────────────────────────────────
    public function productionPlanStatus(Request $request): JsonResponse
    {
        $query = DB::table('production_plans as pp')
            ->leftJoin('users as u', 'pp.approved_by', '=', 'u.user_id')
            ->select([
                'pp.id', 'pp.plan_no', 'pp.plan_name', 'pp.status',
                'pp.plan_date', 'pp.target_date',
                'pp.created_by', 'pp.approved_by',
                DB::raw('(SELECT COUNT(*) FROM production_plan_items WHERE production_plan_id = pp.id) as item_count'),
                DB::raw('(SELECT COUNT(*) FROM production_plan_items ppi WHERE ppi.production_plan_id = pp.id AND ppi.wo_id IS NOT NULL) as wo_created'),
                DB::raw('(SELECT COALESCE(SUM(ppi.qty),0) FROM production_plan_items ppi WHERE ppi.production_plan_id = pp.id) as total_planned_qty'),
                'pp.created_at',
            ]);

        $this->applyDateFilters($query, $request, 'pp.plan_date');
        if ($request->filled('status')) $query->where('pp.status', $request->status);

        $query->orderByDesc('pp.plan_date');
        $rows = $query->get()->map(function ($r) {
            $r->wo_completion_pct = $r->item_count > 0
                ? round($r->wo_created / $r->item_count * 100, 1)
                : 0;
            return $r;
        });

        $totals = [
            'total_plans'    => $rows->count(),
            'total_items'    => $rows->sum('item_count'),
            'total_wo'       => $rows->sum('wo_created'),
            'total_qty'      => $rows->sum('total_planned_qty'),
            'by_status'      => $rows->groupBy('status')->map->count()->toArray(),
        ];

        return ApiResponse::success(compact('rows', 'totals'), 'Production plan status report generated');
    }

    // ── 10. Labour Utilisation Report ─────────────────────────────────────────
    public function labourUtilisation(Request $request): JsonResponse
    {
        $groupBy = $request->get('group_by', 'operator');

        $selectFields = $groupBy === 'role'
            ? ['wol.role as group_label']
            : ['wol.operator_name as group_label'];

        $query = DB::table('work_order_labour as wol')
            ->join('work_orders as wo', 'wo.id', '=', 'wol.work_order_id')
            ->leftJoin('work_centres as wc', 'wo.work_centre_id', '=', 'wc.id')
            ->select(array_merge($selectFields, [
                DB::raw('COUNT(DISTINCT wol.work_order_id) as order_count'),
                DB::raw('SUM(wol.hours_worked) as total_hours'),
                DB::raw('SUM(wol.total_cost) as total_cost'),
                DB::raw('AVG(wol.rate_per_hour) as avg_rate'),
                DB::raw('SUM(wo.actual_qty_produced) as total_output'),
                DB::raw('ROUND(SUM(wo.actual_qty_produced) / NULLIF(SUM(wol.hours_worked),0), 2) as output_per_hour'),
            ]));

        $this->applyDateFilters($query, $request, 'wol.work_date');
        if ($request->filled('work_centre_id')) $query->where('wo.work_centre_id', $request->work_centre_id);

        $groupByCol = $groupBy === 'role' ? 'wol.role' : 'wol.operator_name';
        $query->groupBy($groupByCol)->orderByDesc('total_cost');

        $rows = $query->get();

        $totals = [
            'total_entries'  => $rows->count(),
            'total_hours'    => round($rows->sum('total_hours'), 2),
            'total_cost'     => $rows->sum('total_cost'),
            'avg_rate'       => $rows->count() ? round($rows->avg('avg_rate'), 2) : 0,
        ];

        return ApiResponse::success(compact('rows', 'totals'), 'Labour utilisation report generated');
    }

    // ── CSV Export ─────────────────────────────────────────────────────────────
    public function export(Request $request): Response
    {
        $report = $request->get('report', 'production-orders');

        $data   = match($report) {
            'efficiency'            => $this->efficiency($request),
            'bom-utilisation'       => $this->bomUtilisation($request),
            'output-summary'        => $this->outputSummary($request),
            'cost-of-production'    => $this->costOfProduction($request),
            'wo-variance'           => $this->woVariance($request),
            'production-plan-status'=> $this->productionPlanStatus($request),
            'labour-utilisation'    => $this->labourUtilisation($request),
            default                 => $this->productionOrders($request),
        };

        $payload = json_decode($data->getContent(), true)['data'] ?? [];
        $rows    = $payload['rows'] ?? [];

        if (!$rows) {
            return response('No data', 204);
        }

        $headers = array_keys((array) (object) $rows[0]);
        $csv     = implode(',', $headers) . "\n";
        foreach ($rows as $row) {
            $values = array_map(function ($v) {
                if (is_null($v))   return '';
                if (is_string($v)) return '"' . str_replace('"', '""', $v) . '"';
                return $v;
            }, (array)(object)$row);
            $csv .= implode(',', $values) . "\n";
        }

        $filename = "mfg-{$report}-" . now()->format('Y-m-d') . ".csv";

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────
    private function applyDateFilters($query, Request $request, string $col): void
    {
        if ($request->filled('date_from')) $query->whereDate($col, '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->whereDate($col, '<=', $request->date_to);
    }
}
