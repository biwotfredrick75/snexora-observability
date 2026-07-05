<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalInquiryController extends Controller
{
    // Strips per-farmer suffix (-F<id>) so batch entries collapse into one row.
    private const BATCH_EXPR = "REGEXP_REPLACE(gt.reference, '-F[0-9]+$', '')";

    // gld_transactions.type uses FrontAccounting-style numeric codes — these
    // don't correspond to transaction_references.id (that's an unrelated
    // auto-increment key), so names are resolved from this fixed map instead.
    private const TYPE_LABELS = [
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

    // ── Journal list (grouped by type + batch reference) ──────────────────────
    public function index(Request $request): JsonResponse
    {
        $bx = self::BATCH_EXPR;

        $query = DB::table('gld_transactions as gt')
            ->selectRaw("
                gt.type,
                {$bx}                    AS batch_ref,
                MIN(gt.tran_date)        AS tran_date,
                MIN(gt.narration)        AS narration,
                MIN(gt.created_by)       AS created_by,
                COUNT(*)                 AS line_count,
                SUM(CASE WHEN gt.amount > 0 THEN gt.amount ELSE 0 END)      AS total_debit,
                SUM(CASE WHEN gt.amount < 0 THEN ABS(gt.amount) ELSE 0 END) AS total_credit
            ")
            ->groupByRaw("gt.type, {$bx}");

        if ($request->filled('from'))      $query->where('gt.tran_date', '>=', $request->from);
        if ($request->filled('to'))        $query->where('gt.tran_date', '<=', $request->to);
        if ($request->filled('type'))      $query->where('gt.type', $request->type);
        if ($request->filled('reference')) $query->havingRaw("{$bx} LIKE ?", ['%' . $request->reference . '%']);

        $journals = $query
            ->orderByRaw('MIN(gt.tran_date) DESC')
            ->orderByRaw("{$bx} DESC")
            ->paginate($request->per_page ?? 25);

        $journals->getCollection()->transform(function ($j) {
            $j->type_name = self::TYPE_LABELS[$j->type] ?? "Type {$j->type}";
            return $j;
        });

        return ApiResponse::success($journals, 'Journal inquiry');
    }

    // ── GL lines for one batch (all trans_nos sharing the same batch_ref) ─────
    public function batchLines(Request $request): JsonResponse
    {
        $request->validate([
            'type'      => 'required|integer',
            'batch_ref' => 'required|string',
        ]);

        $bx = "REGEXP_REPLACE(gt.reference, '-F[0-9]+$', '')";

        $lines = DB::table('gld_transactions as gt')
            ->leftJoin('gl_accounts as cm', 'cm.code', '=', 'gt.account_code')
            ->where('gt.type', $request->type)
            ->whereRaw("{$bx} = ?", [$request->batch_ref])
            ->select(
                'gt.id',
                'gt.trans_no',
                'gt.account_code',
                DB::raw('COALESCE(cm.name, gt.account_code) AS account_name'),
                'gt.narration',
                'gt.amount',
                'gt.tran_date',
                'gt.reference',
                'gt.created_by',
            )
            ->orderBy('gt.trans_no')
            ->orderBy('gt.id')
            ->get();

        return ApiResponse::success($lines, 'Batch GL lines');
    }

    // ── GL lines for a single trans_no (legacy / direct access) ──────────────
    public function lines(int $type, int $transNo): JsonResponse
    {
        $lines = DB::table('gld_transactions as gt')
            ->leftJoin('gl_accounts as cm', 'cm.code', '=', 'gt.account_code')
            ->where('gt.type', $type)
            ->where('gt.trans_no', $transNo)
            ->select(
                'gt.id',
                'gt.account_code',
                DB::raw('COALESCE(cm.name, gt.account_code) AS account_name'),
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
            ->pluck('type')
            ->sort();

        $names = $usedTypes->map(fn($code) => [
            'id'         => $code,
            'trans_name' => self::TYPE_LABELS[$code] ?? "Type {$code}",
        ])->values();

        return ApiResponse::success($names, 'Transaction types');
    }
}
