<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalInquiryController extends Controller
{
    // ── Journal list (grouped by type + trans_no) ─────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('gld_transactions as gt')
            ->selectRaw('
                gt.type,
                gt.trans_no,
                MIN(gt.tran_date)   AS tran_date,
                MIN(gt.reference)   AS reference,
                MIN(gt.narration)   AS narration,
                MIN(gt.created_by)  AS created_by,
                COUNT(*)            AS line_count,
                SUM(CASE WHEN gt.amount > 0 THEN gt.amount ELSE 0 END)        AS total_debit,
                SUM(CASE WHEN gt.amount < 0 THEN ABS(gt.amount) ELSE 0 END)   AS total_credit
            ')
            ->groupBy('gt.type', 'gt.trans_no');

        if ($request->filled('from'))      $query->where('gt.tran_date', '>=', $request->from);
        if ($request->filled('to'))        $query->where('gt.tran_date', '<=', $request->to);
        if ($request->filled('type'))      $query->where('gt.type', $request->type);
        if ($request->filled('reference')) $query->where('gt.reference', 'LIKE', '%' . $request->reference . '%');

        $journals = $query
            ->orderByRaw('MIN(gt.tran_date) DESC')
            ->orderByRaw('gt.trans_no DESC')
            ->paginate($request->per_page ?? 25);

        // Attach type names
        $typeNames = DB::table('transaction_references')
            ->pluck('trans_name', 'id');

        $journals->getCollection()->transform(function ($j) use ($typeNames) {
            $j->type_name = $typeNames[$j->type] ?? "Type {$j->type}";
            return $j;
        });

        return ApiResponse::success($journals, 'Journal inquiry');
    }

    // ── GL lines for one journal ──────────────────────────────────────────────
    public function lines(int $type, int $transNo): JsonResponse
    {
        $lines = DB::table('gld_transactions as gt')
            ->leftJoin('0_chart_master as cm', 'cm.account_code', '=', 'gt.account_code')
            ->where('gt.type', $type)
            ->where('gt.trans_no', $transNo)
            ->select(
                'gt.id',
                'gt.account_code',
                DB::raw('COALESCE(cm.account_name, gt.account_code) AS account_name'),
                'gt.narration',
                'gt.amount',
                'gt.tran_date',
                'gt.reference',
                'gt.created_by',
            )
            ->orderBy('gt.id')
            ->get();

        return ApiResponse::success($lines, 'Journal lines');
    }

    // ── Transaction type list for filter dropdown ─────────────────────────────
    public function types(): JsonResponse
    {
        // Only return types that have actual GL data
        $usedTypes = DB::table('gld_transactions')
            ->select('type')
            ->distinct()
            ->pluck('type');

        $names = DB::table('transaction_references')
            ->whereIn('id', $usedTypes)
            ->orderBy('id')
            ->get(['id', 'trans_name']);

        return ApiResponse::success($names, 'Transaction types');
    }
}
