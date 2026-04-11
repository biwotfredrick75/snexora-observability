<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FarmerPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FarmerAdvanceReportController extends Controller
{
    public function formData(Request $request): JsonResponse
    {
        $search = $request->input('search', '');
        $farmersQuery = DB::table('farmers')
            ->where('status', 'active')
            ->orderBy('full_name');

        if ($search) {
            $farmersQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('farmer_no', 'like', "%{$search}%");
            });
        }

        $farmers = $farmersQuery->limit(100)->get(['id', 'farmer_no', 'full_name']);

        return ApiResponse::success(compact('farmers'), 'Form data');
    }

    public function index(Request $request): JsonResponse
    {
        $query = FarmerPayment::with('farmer')
            ->where('type', $request->input('type', 'advance'))
            ->orderByDesc('date_paid');

        if ($request->filled('farmer_id') && !$request->boolean('all')) {
            $query->where('farmer_id', $request->farmer_id);
        }
        if ($request->filled('from_date')) {
            $query->where('date_paid', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('date_paid', '<=', $request->to_date);
        }

        $payments = $query->get()->map(fn ($p) => [
            'id'            => $p->id,
            'farmer_no'     => $p->farmer?->farmer_no,
            'full_name'     => $p->farmer?->full_name,
            'date_paid'     => $p->date_paid?->format('Y-m-d'),
            'reference'     => $p->reference,
            'type'          => $p->type,
            'amount'        => $p->amount_payment,
            'bank_charge'   => $p->bank_charge,
            'cheque_no'     => $p->cheque_no,
            'memo'          => $p->memo,
        ]);

        $totals = [
            'count'          => $payments->count(),
            'total_amount'   => round($payments->sum('amount'), 2),
            'total_charges'  => round($payments->sum('bank_charge'), 2),
        ];

        return ApiResponse::success(
            compact('payments', 'totals'),
            'Advance report'
        );
    }
}
