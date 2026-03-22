<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GraderCollectionReportController extends Controller
{
    // ── Base query: joins + filters only ─────────────────────────────────────
    private function buildBaseQuery(array $filters): Builder
    {
        $query = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp',             'mp.id',  '=', 'mpi.purchase_id')
            ->leftJoin('farmers as f',                 'f.id',   '=', 'mpi.farmer_id')
            ->leftJoin('milk_routes as mr',            'mr.id',  '=', 'mp.route_id')
            ->leftJoin('milk_collection_shifts as sh', 'sh.id',  '=', 'mp.shift_id')
            ->leftJoin('inventory_locations as il',    'il.id',  '=', 'mp.grader_id');

        if (! empty($filters['farmer_id']))    $query->where('mpi.farmer_id',    $filters['farmer_id']);
        if (! empty($filters['route_id']))     $query->where('mp.route_id',      $filters['route_id']);
        if (! empty($filters['shift_id']))     $query->where('mp.shift_id',      $filters['shift_id']);
        if (! empty($filters['grader_id']))    $query->where('mp.grader_id',     $filters['grader_id']);
        if (! empty($filters['start_date']))   $query->whereDate('mp.invoice_date', '>=', $filters['start_date']);
        if (! empty($filters['end_date']))     $query->whereDate('mp.invoice_date', '<=', $filters['end_date']);

        return $query;
    }

    // ── Data query — ordered by grader first ──────────────────────────────────
    private function buildDataQuery(array $filters): Builder
    {
        $query = $this->buildBaseQuery($filters);

        if (! empty($filters['summary']) && $filters['summary'] === 'yes') {
            return $query
                ->groupBy('il.id', 'il.code', 'il.name', 'f.id', 'f.farmer_no', 'f.full_name', 'f.phone')
                ->selectRaw('
                    il.code  as grader_code,
                    il.name  as grader_name,
                    f.farmer_no, f.full_name as farmer_name, f.phone,
                    NULL as date, NULL as shift, NULL as route,
                    SUM(mpi.quantity) as qty,
                    AVG(mpi.unit_price) as unit_price,
                    SUM(mpi.total_price) as total_price
                ')
                ->orderBy('il.name')
                ->orderBy('f.full_name');
        }

        return $query
            ->select([
                'il.code          as grader_code',
                'il.name          as grader_name',
                'f.farmer_no      as farmer_no',
                'f.full_name      as farmer_name',
                'f.phone          as phone',
                'mp.invoice_date  as date',
                'sh.description   as shift',
                'mr.route_name    as route',
                'mpi.quantity     as qty',
                'mpi.unit_price   as unit_price',
                'mpi.total_price  as total_price',
            ])
            ->orderBy('il.name')
            ->orderBy('mp.invoice_date')
            ->orderBy('f.full_name');
    }

    // ── Totals ────────────────────────────────────────────────────────────────
    private function buildTotals(array $filters): object
    {
        return $this->buildBaseQuery($filters)
            ->selectRaw('SUM(mpi.quantity) as total_qty, SUM(mpi.total_price) as total_amount')
            ->first();
    }

    // ── JSON preview ──────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $filters  = $request->only(['farmer_id', 'route_id', 'shift_id', 'grader_id',
                                     'start_date', 'end_date', 'summary']);
        $rows     = $this->buildDataQuery($filters)->get();
        $totalQty = $rows->sum('qty');

        return ApiResponse::success([
            'rows'      => $rows,
            'total_qty' => $totalQty,
        ], 'Grader Collection Report');
    }

    // ── Dropdown form data ────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'farmers' => DB::table('farmers')
                ->where('status', 'active')
                ->orderBy('full_name')
                ->get(['id', 'farmer_no', 'full_name']),

            'routes' => DB::table('milk_routes')
                ->orderBy('route_name')
                ->get(['id', 'route_name', 'route_code']),

            'shifts' => DB::table('milk_collection_shifts')
                ->where('active', true)
                ->orderBy('description')
                ->get(['id', 'description']),

            'graders' => DB::table('inventory_locations')
                ->whereIn('type', ['grader', 'vendor'])
                ->where('inactive', false)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ], 'Form data');
    }

    // ── CSV export ────────────────────────────────────────────────────────────
    public function exportExcel(Request $request)
    {
        set_time_limit(300);

        $filters  = $request->only(['farmer_id', 'route_id', 'shift_id', 'grader_id',
                                     'start_date', 'end_date', 'summary']);
        $query    = $this->buildDataQuery($filters);
        $totals   = $this->buildTotals($filters);
        $filename = 'grader-collection-' . now()->format('Ymd-His') . '.csv';

        return response()->stream(function () use ($query, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['#', 'Grader Code', 'Collection Clerk / Station', 'Member No',
                           'Farmer Name', 'Phone', 'Date', 'Route', 'Shift',
                           'Qty (L)', 'Unit Price', 'Total Amount']);

            $i = 0;
            $query->chunk(500, function ($rows) use ($out, &$i) {
                foreach ($rows as $r) {
                    fputcsv($out, [
                        ++$i,
                        $r->grader_code  ?? '',
                        $r->grader_name  ?? '',
                        $r->farmer_no    ?? '',
                        $r->farmer_name  ?? '',
                        $r->phone        ?? '',
                        $r->date         ?? '',
                        $r->route        ?? '',
                        $r->shift        ?? '',
                        number_format((float)($r->qty        ?? 0), 3, '.', ''),
                        number_format((float)($r->unit_price ?? 0), 4, '.', ''),
                        number_format((float)($r->total_price ?? 0), 2, '.', ''),
                    ]);
                }
                ob_flush(); flush();
            });

            fputcsv($out, ['', '', '', '', '', '', '', '', 'TOTAL',
                number_format((float)($totals->total_qty    ?? 0), 3, '.', ''),
                '',
                number_format((float)($totals->total_amount ?? 0), 2, '.', ''),
            ]);

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Accel-Buffering'   => 'no',
            'Cache-Control'       => 'no-cache',
        ]);
    }

    // ── PDF export ────────────────────────────────────────────────────────────
    public function exportPdf(Request $request)
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(180);

        $filters    = $request->only(['farmer_id', 'route_id', 'shift_id', 'grader_id',
                                       'start_date', 'end_date', 'summary']);
        $totalCount = $this->buildBaseQuery($filters)->count();
        $limit      = 5000;
        $truncated  = $totalCount > $limit;

        $rows    = $this->buildDataQuery($filters)->limit($limit)->get();
        $totals  = $this->buildTotals($filters);
        $company = DB::table('company_preferences')->first();

        $pdf = Pdf::loadView('reports.grader_collection', [
            'rows'        => $rows,
            'filters'     => $filters,
            'company'     => $company,
            'totalQty'    => $totals->total_qty    ?? 0,
            'totalAmount' => $totals->total_amount ?? 0,
            'printDate'   => now()->format('d/m/Y H:i'),
            'truncated'   => $truncated,
            'shownCount'  => $rows->count(),
            'totalCount'  => $totalCount,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('grader-collection-' . now()->format('Ymd-His') . '.pdf');
    }
}
