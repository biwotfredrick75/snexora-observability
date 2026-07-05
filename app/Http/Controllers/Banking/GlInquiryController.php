<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GlInquiryController extends Controller
{
    /**
     * GET /api/banking/gl-inquiry
     * Filters: account_code, from, to, dimension_id, dimension2_id, amount_min, amount_max
     */
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('gld_transactions as g')
            ->leftJoin('gl_accounts as a', 'g.account_code', '=', 'a.code')
            ->leftJoin('dimensions as d1', 'g.dimension_id', '=', 'd1.id')
            ->leftJoin('dimensions as d2', 'g.dimension2_id', '=', 'd2.id')
            ->select([
                'g.id', 'g.type', 'g.trans_no', 'g.tran_date',
                'g.account_code', 'a.name as account_name',
                'g.dimension_id', 'd1.name as dimension1_name',
                'g.dimension2_id', 'd2.name as dimension2_name',
                'g.reference', 'g.narration',
                'g.amount',
                DB::raw('CASE WHEN g.amount > 0 THEN g.amount ELSE 0 END as debit'),
                DB::raw('CASE WHEN g.amount < 0 THEN ABS(g.amount) ELSE 0 END as credit'),
                'g.created_by',
            ])
            ->orderBy('g.tran_date')
            ->orderBy('g.trans_no');

        if ($request->filled('account_code'))
            $q->where('g.account_code', $request->account_code);

        if ($request->filled('from'))
            $q->where('g.tran_date', '>=', $request->from);

        if ($request->filled('to'))
            $q->where('g.tran_date', '<=', $request->to);

        if ($request->filled('dimension_id'))
            $q->where('g.dimension_id', $request->dimension_id);

        if ($request->filled('dimension2_id'))
            $q->where('g.dimension2_id', $request->dimension2_id);

        if ($request->filled('amount_min'))
            $q->where(DB::raw('ABS(g.amount)'), '>=', $request->amount_min);

        if ($request->filled('amount_max') && (float)$request->amount_max > 0)
            $q->where(DB::raw('ABS(g.amount)'), '<=', $request->amount_max);

        if ($request->filled('type'))
            $q->where('g.type', $request->type);

        $rows = $q->limit(2000)->get();

        $totalDebit  = $rows->sum('debit');
        $totalCredit = $rows->sum('credit');

        return ApiResponse::success([
            'rows'         => $rows,
            'total_debit'  => $totalDebit,
            'total_credit' => $totalCredit,
            'net'          => $totalDebit - $totalCredit,
        ]);
    }

    /** GET /api/banking/gl-inquiry/type-labels — only types that have GL data */
    public function typeLabels(): JsonResponse
    {
        $allLabels = [
            0  => 'Journal Entry',
            1  => 'Bank Deposit',
            2  => 'Bank Payment',
            4  => 'Funds Transfer',
            10 => 'Sales Invoice',
            11 => 'Credit Note',
            12 => 'Customer Payment',
            13 => 'Customer Deposit',
            20 => 'Supplier Invoice',
            21 => 'Supplier Credit',
            22 => 'Supplier Payment',
            25 => 'Purchase Order',
            26 => 'Delivery Note',
            32 => 'Location Transfer',
            35 => 'Inventory Adjustment',
            40 => 'Work Order',
            41 => 'Work Order Issue',
            50 => 'Payroll',
        ];

        $usedTypes = DB::table('gld_transactions')->select('type')->distinct()->pluck('type');

        $labels = collect($allLabels)
            ->filter(fn($name, $code) => $usedTypes->contains($code))
            ->all();

        return ApiResponse::success($labels);
    }

    /** GET /api/banking/gl-inquiry/transaction?type={type}&trans_no={trans_no} */
    public function transaction(Request $request): JsonResponse
    {
        $request->validate([
            'type'     => 'required|integer',
            'trans_no' => 'required|integer',
        ]);

        $rows = DB::table('gld_transactions as g')
            ->leftJoin('gl_accounts as a', 'g.account_code', '=', 'a.code')
            ->leftJoin('dimensions as d1', 'g.dimension_id', '=', 'd1.id')
            ->leftJoin('dimensions as d2', 'g.dimension2_id', '=', 'd2.id')
            ->select([
                'g.id', 'g.type', 'g.trans_no', 'g.tran_date',
                'g.account_code', 'a.name as account_name',
                'd1.name as dimension1_name',
                'd2.name as dimension2_name',
                'g.reference', 'g.narration',
                DB::raw('CASE WHEN g.amount > 0 THEN g.amount ELSE 0 END as debit'),
                DB::raw('CASE WHEN g.amount < 0 THEN ABS(g.amount) ELSE 0 END as credit'),
            ])
            ->where('g.type', $request->type)
            ->where('g.trans_no', $request->trans_no)
            ->orderBy('g.id')
            ->get();

        return ApiResponse::success([
            'rows'         => $rows,
            'total_debit'  => $rows->sum('debit'),
            'total_credit' => $rows->sum('credit'),
        ]);
    }
}
