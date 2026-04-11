<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductSalesReportController extends Controller
{
    // ── Filter types ──────────────────────────────────────────────────────────
    const TYPES = [
        'dates_per_customer'          => 'Between Dates Per Customer',
        'dates_per_item'              => 'Between Dates Per Item',
        'dates_per_location'          => 'Between Dates Per Store/Location',
        'dates_per_location_category' => 'Between Dates Per Store/Location per Item Category',
        'all_per_customer'            => 'All Per Customer',
        'all_per_item'                => 'All Per Item',
    ];

    // ── Base query ────────────────────────────────────────────────────────────
    private function base(Request $request)
    {
        $type = $request->get('type', 'dates_per_customer');
        $from = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $request->get('date_to',   now()->toDateString());

        $q = DB::table('sales_invoice_items as sii')
            ->join('sales_invoices as si',       'si.id',        '=', 'sii.inv_id')
            ->leftJoin('customers as c',         'c.debtor_no',  '=', 'si.debtor_no')
            ->leftJoin('inventory_locations as l','l.id',        '=', 'si.location_id')
            ->leftJoin('items as it',            'it.stock_id',  '=', 'sii.stock_id')
            ->leftJoin('item_categories as ic',  'ic.id',        '=', 'it.category_id')
            ->where('si.status', 'placed');

        // Apply date filter only for "between dates" types
        if (str_starts_with($type, 'dates_')) {
            $q->whereBetween('si.invoice_date', [$from, $to]);
        }

        // Optional extra filters
        if ($request->filled('debtor_no'))   $q->where('si.debtor_no',  $request->get('debtor_no'));
        if ($request->filled('location_id')) $q->where('si.location_id',$request->get('location_id'));
        if ($request->filled('stock_id'))    $q->where('sii.stock_id',  $request->get('stock_id'));
        if ($request->filled('category_id')) $q->where('it.category_id',$request->get('category_id'));

        return $q;
    }

    // ── Form data ─────────────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        $customers = DB::table('customers')
            ->where('inactive', false)
            ->orderBy('name')
            ->get(['debtor_no', 'name']);

        $locations = DB::table('inventory_locations')
            ->where('inactive', false)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $items = DB::table('items')
            ->where('inactive', false)
            ->orderBy('description')
            ->get(['stock_id', 'description']);

        $categories = DB::table('item_categories')
            ->where('inactive', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        return ApiResponse::success([
            'types'      => collect(self::TYPES)->map(fn($label, $id) => ['id' => $id, 'label' => $label])->values(),
            'customers'  => $customers,
            'locations'  => $locations,
            'items'      => $items,
            'categories' => $categories,
        ], 'Form data loaded');
    }

    // ── Report data ───────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $type = $request->get('type', 'dates_per_customer');
        $from = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $request->get('date_to',   now()->toDateString());

        $rows = match ($type) {
            'dates_per_customer', 'all_per_customer' => $this->byCustomer($request),
            'dates_per_item',     'all_per_item'     => $this->byItem($request),
            'dates_per_location'                     => $this->byLocation($request),
            'dates_per_location_category'            => $this->byLocationCategory($request),
            default                                  => collect([]),
        };

        $grandQty    = $rows->sum('total_qty');
        $grandAmount = $rows->sum('total_amount');

        // Add share %
        $rows = $rows->map(fn($r) => array_merge((array)$r, [
            'share' => $grandQty > 0 ? round((float)$r->total_qty / $grandQty * 100, 1) : 0,
        ]));

        return ApiResponse::success([
            'type'    => $type,
            'label'   => self::TYPES[$type] ?? $type,
            'from'    => $from,
            'to'      => $to,
            'rows'    => $rows->values(),
            'totals'  => [
                'qty'      => round($grandQty, 2),
                'amount'   => round($grandAmount, 2),
                'invoices' => $rows->sum('invoice_count'),
            ],
        ], 'Product sales report loaded');
    }

    private function byCustomer(Request $request)
    {
        return $this->base($request)
            ->selectRaw('
                c.debtor_no,
                COALESCE(c.name, si.debtor_no)      AS customer_name,
                COUNT(DISTINCT si.id)                AS invoice_count,
                SUM(sii.qty)                         AS total_qty,
                SUM(sii.line_total)                  AS total_amount,
                AVG(sii.price)                       AS avg_price,
                MAX(si.invoice_date)                 AS last_invoice
            ')
            ->groupBy('c.debtor_no', 'c.name', 'si.debtor_no')
            ->orderByDesc('total_amount')
            ->get();
    }

    private function byItem(Request $request)
    {
        return $this->base($request)
            ->selectRaw('
                sii.stock_id,
                sii.description                      AS item_description,
                COALESCE(ic.name, "Uncategorised")   AS category_name,
                COUNT(DISTINCT si.id)                AS invoice_count,
                SUM(sii.qty)                         AS total_qty,
                SUM(sii.line_total)                  AS total_amount,
                AVG(sii.price)                       AS avg_price,
                MAX(si.invoice_date)                 AS last_invoice
            ')
            ->groupBy('sii.stock_id', 'sii.description', 'ic.name')
            ->orderByDesc('total_qty')
            ->get();
    }

    private function byLocation(Request $request)
    {
        return $this->base($request)
            ->selectRaw('
                COALESCE(l.name, "Unknown")          AS location_name,
                COALESCE(l.code, "-")                AS location_code,
                COUNT(DISTINCT si.id)                AS invoice_count,
                COUNT(DISTINCT si.debtor_no)         AS customer_count,
                SUM(sii.qty)                         AS total_qty,
                SUM(sii.line_total)                  AS total_amount,
                MAX(si.invoice_date)                 AS last_invoice
            ')
            ->groupBy('l.name', 'l.code')
            ->orderByDesc('total_amount')
            ->get();
    }

    private function byLocationCategory(Request $request)
    {
        return $this->base($request)
            ->selectRaw('
                COALESCE(l.name, "Unknown")          AS location_name,
                COALESCE(l.code, "-")                AS location_code,
                COALESCE(ic.name, "Uncategorised")   AS category_name,
                COUNT(DISTINCT si.id)                AS invoice_count,
                SUM(sii.qty)                         AS total_qty,
                SUM(sii.line_total)                  AS total_amount
            ')
            ->groupBy('l.name', 'l.code', 'ic.name')
            ->orderBy('l.name')
            ->orderByDesc('total_amount')
            ->get();
    }

    // ── CSV Export ────────────────────────────────────────────────────────────
    public function exportExcel(Request $request)
    {
        $type   = $request->get('type', 'dates_per_customer');
        $from   = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to     = $request->get('date_to',   now()->toDateString());
        $label  = self::TYPES[$type] ?? $type;
        $name   = 'product-sales-' . now()->format('Ymd-His') . '.csv';

        // Re-use the same query logic
        $rows = match ($type) {
            'dates_per_customer', 'all_per_customer' => $this->byCustomer($request),
            'dates_per_item',     'all_per_item'     => $this->byItem($request),
            'dates_per_location'                     => $this->byLocation($request),
            'dates_per_location_category'            => $this->byLocationCategory($request),
            default                                  => collect([]),
        };

        $headers = match ($type) {
            'dates_per_customer', 'all_per_customer'  => ['#', 'Customer', 'Invoices', 'Total Qty', 'Total Amount', 'Avg Price', 'Last Invoice'],
            'dates_per_item',     'all_per_item'      => ['#', 'Stock ID', 'Description', 'Category', 'Invoices', 'Total Qty', 'Total Amount', 'Avg Price', 'Last Invoice'],
            'dates_per_location'                      => ['#', 'Location', 'Code', 'Invoices', 'Customers', 'Total Qty', 'Total Amount', 'Last Invoice'],
            'dates_per_location_category'             => ['#', 'Location', 'Code', 'Category', 'Invoices', 'Total Qty', 'Total Amount'],
            default                                   => [],
        };

        return response()->stream(function () use ($rows, $headers, $type, $label, $from, $to) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ["Product Sales Report — {$label}"]);
            fputcsv($out, ["Period: {$from} to {$to}", '', "Generated: " . now()->format('d/m/Y H:i')]);
            fputcsv($out, []);
            fputcsv($out, $headers);

            $i = 0;
            foreach ($rows as $r) {
                $r = (array)$r;
                $row = match ($type) {
                    'dates_per_customer', 'all_per_customer' => [
                        ++$i, $r['customer_name'] ?? '', $r['invoice_count'] ?? 0,
                        number_format((float)($r['total_qty'] ?? 0), 2, '.', ''),
                        number_format((float)($r['total_amount'] ?? 0), 2, '.', ''),
                        number_format((float)($r['avg_price'] ?? 0), 4, '.', ''),
                        $r['last_invoice'] ?? '',
                    ],
                    'dates_per_item', 'all_per_item' => [
                        ++$i, $r['stock_id'] ?? '', $r['item_description'] ?? '', $r['category_name'] ?? '',
                        $r['invoice_count'] ?? 0,
                        number_format((float)($r['total_qty'] ?? 0), 2, '.', ''),
                        number_format((float)($r['total_amount'] ?? 0), 2, '.', ''),
                        number_format((float)($r['avg_price'] ?? 0), 4, '.', ''),
                        $r['last_invoice'] ?? '',
                    ],
                    'dates_per_location' => [
                        ++$i, $r['location_name'] ?? '', $r['location_code'] ?? '',
                        $r['invoice_count'] ?? 0, $r['customer_count'] ?? 0,
                        number_format((float)($r['total_qty'] ?? 0), 2, '.', ''),
                        number_format((float)($r['total_amount'] ?? 0), 2, '.', ''),
                        $r['last_invoice'] ?? '',
                    ],
                    'dates_per_location_category' => [
                        ++$i, $r['location_name'] ?? '', $r['location_code'] ?? '', $r['category_name'] ?? '',
                        $r['invoice_count'] ?? 0,
                        number_format((float)($r['total_qty'] ?? 0), 2, '.', ''),
                        number_format((float)($r['total_amount'] ?? 0), 2, '.', ''),
                    ],
                    default => [],
                };
                fputcsv($out, $row);
            }

            $grandQty    = $rows->sum('total_qty');
            $grandAmount = $rows->sum('total_amount');
            fputcsv($out, []);
            fputcsv($out, ['', 'TOTAL', '', number_format($grandQty, 2, '.', ''), number_format($grandAmount, 2, '.', '')]);
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'X-Accel-Buffering'   => 'no',
            'Cache-Control'       => 'no-cache',
        ]);
    }
}
