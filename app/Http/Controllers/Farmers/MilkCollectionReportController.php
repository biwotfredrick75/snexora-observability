<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class MilkCollectionReportController extends Controller
{
    // ── Base query: joins + filters only (no select, no orderBy) ─────────────
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
        if (! empty($filters['pricing_type'])) $query->where('mp.pricing_type', strtolower($filters['pricing_type']));

        return $query;
    }

    // ── Full data query (base + columns + order + optional summary) ───────────
    private function buildDataQuery(array $filters): Builder
    {
        $query = $this->buildBaseQuery($filters);

        if (! empty($filters['summary']) && $filters['summary'] === 'yes') {
            return $query
                ->groupBy('f.id', 'f.farmer_no', 'f.full_name', 'f.phone',
                           'mr.route_name', 'il.name', 'sh.description', 'mp.created_by')
                ->selectRaw('
                    f.farmer_no, f.full_name as farmer_name, f.phone,
                    NULL as date, mr.route_name as route, il.name as store,
                    sh.description as shift, NULL as time, mp.created_by as user,
                    SUM(mpi.quantity) as qty,
                    AVG(mpi.unit_price) as unit_price,
                    SUM(mpi.total_price) as total_price
                ')
                ->orderBy('f.full_name');
        }

        return $query
            ->select([
                'f.farmer_no      as farmer_no',
                'f.full_name      as farmer_name',
                'f.phone          as phone',
                'mp.invoice_date  as date',
                'mr.route_name    as route',
                'il.name          as store',
                'sh.description   as shift',
                'mp.created_at    as time',
                'mp.created_by    as user',
                'mpi.quantity     as qty',
                'mpi.unit_price   as unit_price',
                'mpi.total_price  as total_price',
            ])
            ->orderBy('mp.invoice_date')
            ->orderBy('f.full_name');
    }

    // ── Aggregate totals without loading all rows ─────────────────────────────
    private function buildTotals(array $filters): object
    {
        return $this->buildBaseQuery($filters)
            ->selectRaw('SUM(mpi.quantity) as total_qty, SUM(mpi.total_price) as total_amount')
            ->first();
    }

    // ── JSON for the frontend table preview ───────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $filters  = $request->only(['farmer_id', 'route_id', 'shift_id', 'grader_id',
                                     'start_date', 'end_date', 'pricing_type', 'summary']);
        $rows     = $this->buildDataQuery($filters)->get();
        $totalQty = $rows->sum('qty');

        return ApiResponse::success([
            'rows'      => $rows,
            'total_qty' => $totalQty,
        ], 'Milk Collection Report');
    }

    // ── Form dropdown data ────────────────────────────────────────────────────
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

            'pricing_types' => [
                ['value' => '',        'label' => 'All'],
                ['value' => 'normal',  'label' => 'Normal'],
                ['value' => 'special', 'label' => 'Special'],
                ['value' => 'monthly', 'label' => 'Monthly'],
            ],
        ], 'Form data');
    }

    // ── Excel export — formatted XLSX with fixed column widths ──────────────
    public function exportExcel(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

        $filters  = $request->only(['farmer_id', 'route_id', 'shift_id', 'grader_id',
                                     'start_date', 'end_date', 'pricing_type', 'summary']);
        $query    = $this->buildDataQuery($filters);
        $totals   = $this->buildTotals($filters);
        $company  = DB::table('company_preferences')->first();
        $filename = 'milk-collection-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(
            new MilkCollectionExport($query, $totals, $filters, $company),
            $filename
        );
    }

    // ── PDF export — first 5 000 rows, with truncation notice if more exist ──
    public function exportPdf(Request $request)
    {
        ini_set('memory_limit', '2048M');
        set_time_limit(180);

        $filters   = $request->only(['farmer_id', 'route_id', 'shift_id', 'grader_id',
                                      'start_date', 'end_date', 'pricing_type', 'summary']);
        $totalCount = $this->buildBaseQuery($filters)->count();
        $limit      = 5000;
        $truncated  = $totalCount > $limit;

        $rows    = $this->buildDataQuery($filters)->limit($limit)->get();
        $totals  = $this->buildTotals($filters);
        $company = DB::table('company_preferences')->first();

        $pdf = Pdf::loadView('reports.milk_collection', [
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

        return $pdf->download('milk-collection-' . now()->format('Ymd-His') . '.pdf');
    }
}
