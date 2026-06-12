<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tab    = $request->input('tab', 'farmers');      // farmers | promote | all
        $status = $request->input('status', 'active');    // active | all
        $month  = $request->input('month');
        $year   = $request->input('year');

        if ($tab === 'promote') {
            // Promote Suppliers from the purchase suppliers table
            $query = DB::table('suppliers')->orderBy('supp_name');
            if ($status === 'active') $query->where('inactive', false);

            $rows = $query->get([
                'id', 'supp_no', 'supp_name', 'phone', 'email',
                'address', 'currency', 'inactive',
            ]);

            return ApiResponse::success([
                'tab'      => 'promote',
                'suppliers' => $rows,
                'total'    => $rows->count(),
            ], 'Promote suppliers');
        }

        if ($tab === 'all') {
            $farmers  = DB::table('farmers')
                ->when($status === 'active', fn ($q) => $q->where('status', 'active'))
                ->get(['id', 'farmer_no as supp_no', 'full_name as supp_name',
                       'phone', 'email', 'status', DB::raw("'farmer' as source")]);

            $suppliers = DB::table('suppliers')
                ->when($status === 'active', fn ($q) => $q->where('inactive', false))
                ->get(['id', 'supp_no', 'supp_name', 'phone', 'email',
                       DB::raw("IIF(inactive,0,1) as status"), DB::raw("'supplier' as source")]);

            $all = $farmers->merge($suppliers)->sortBy('supp_name')->values();

            return ApiResponse::success([
                'tab'   => 'all',
                'items' => $all,
                'total' => $all->count(),
            ], 'All suppliers');
        }

        // Default: farmers tab
        $query = DB::table('farmers as f')
            ->leftJoin('milk_routes as r', 'r.id', '=', 'f.route_id')
            ->leftJoin('farmer_banks as b', 'b.id', '=', 'f.bank_id')
            ->orderBy('f.full_name');

        if ($status === 'active') $query->where('f.status', 'active');

        // Filter by month/year of last milk delivery
        if ($month && $year) {
            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate   = date('Y-m-t', strtotime($startDate));
            $query->whereExists(function ($sub) use ($startDate, $endDate) {
                $sub->select(DB::raw(1))
                    ->from('milk_purchase_items as mpi')
                    ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
                    ->whereColumn('mpi.farmer_id', 'f.id')
                    ->whereBetween('mp.invoice_date', [$startDate, $endDate])
                    ->where('mp.status', '!=', 'cancelled');
            });
        }

        $farmers = $query->get([
            'f.id', 'f.farmer_no', 'f.member_no', 'f.full_name',
            'f.phone', 'f.email', 'f.status', 'f.account_no',
            'r.route_name', 'b.name as bank_name',
        ]);

        return ApiResponse::success([
            'tab'     => 'farmers',
            'farmers' => $farmers,
            'total'   => $farmers->count(),
        ], 'Farmers list');
    }
}
