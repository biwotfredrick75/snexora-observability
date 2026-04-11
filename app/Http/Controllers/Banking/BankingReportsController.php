<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankingReportsController extends Controller
{
    // ── Shared: load GL accounts with class info ───────────────────────────
    private function accountsWithClass(): \Illuminate\Support\Collection
    {
        return DB::table('gl_accounts as a')
            ->leftJoin('gl_account_groups as g', 'g.id', '=', 'a.group_id')
            ->leftJoin('gl_account_classes as c', 'c.id', '=', 'g.class_id')
            ->select('a.code', 'a.name', 'g.name as group_name', 'c.name as class_name', 'c.class_type')
            ->where('a.inactive', false)
            ->orderBy('a.code')
            ->get()
            ->keyBy('code');
    }

    // ── Trial Balance ─────────────────────────────────────────────────────
    public function trialBalance(Request $request): JsonResponse
    {
        $from = $request->get('date_from');
        $to   = $request->get('date_to');

        $balances = DB::table('gld_transactions')
            ->select(
                'account_code',
                DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as debit'),
                DB::raw('SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as credit'),
                DB::raw('SUM(amount) as balance')
            )
            ->when($from, fn($q) => $q->where('tran_date', '>=', $from))
            ->when($to,   fn($q) => $q->where('tran_date', '<=', $to))
            ->groupBy('account_code')
            ->get()
            ->keyBy('account_code');

        $accounts = $this->accountsWithClass();

        $rows = $balances->map(function ($b) use ($accounts) {
            $acc = $accounts->get($b->account_code);
            return [
                'account_code' => $b->account_code,
                'account_name' => $acc?->name ?? $b->account_code,
                'group_name'   => $acc?->group_name ?? '—',
                'class_type'   => $acc?->class_type ?? '—',
                'debit'        => round((float) $b->debit,   2),
                'credit'       => round((float) $b->credit,  2),
                'balance'      => round((float) $b->balance, 2),
            ];
        })->sortBy('account_code')->values();

        return ApiResponse::success([
            'rows'          => $rows,
            'total_debit'   => round($rows->sum('debit'),   2),
            'total_credit'  => round($rows->sum('credit'),  2),
            'total_balance' => round($rows->sum('balance'), 2),
        ], 'Trial balance retrieved');
    }

    // ── Profit & Loss ─────────────────────────────────────────────────────
    public function profitLoss(Request $request): JsonResponse
    {
        $from = $request->get('date_from');
        $to   = $request->get('date_to');

        $balances = DB::table('gld_transactions as t')
            ->join('gl_accounts as a', 'a.code', '=', 't.account_code')
            ->join('gl_account_groups as g', 'g.id', '=', 'a.group_id')
            ->join('gl_account_classes as c', 'c.id', '=', 'g.class_id')
            ->whereIn('c.class_type', ['Income', 'Expense'])
            ->select(
                't.account_code',
                'a.name as account_name',
                'g.name as group_name',
                'c.class_type',
                DB::raw('SUM(t.amount) as balance')
            )
            ->when($from, fn($q) => $q->where('t.tran_date', '>=', $from))
            ->when($to,   fn($q) => $q->where('t.tran_date', '<=', $to))
            ->groupBy('t.account_code', 'a.name', 'g.name', 'c.class_type')
            ->orderBy('c.class_type')
            ->orderBy('t.account_code')
            ->get();

        $income  = $balances->where('class_type', 'Income');
        $expense = $balances->where('class_type', 'Expense');

        // Income is credit (negative amounts = credit = income), so negate
        $totalIncome  = round(-$income->sum('balance'),  2);
        $totalExpense = round($expense->sum('balance'),  2);
        $netProfit    = round($totalIncome - $totalExpense, 2);

        return ApiResponse::success([
            'income'        => $income->map(fn($r) => ['account_code' => $r->account_code, 'account_name' => $r->account_name, 'group_name' => $r->group_name, 'amount' => round(-$r->balance, 2)])->values(),
            'expense'       => $expense->map(fn($r) => ['account_code' => $r->account_code, 'account_name' => $r->account_name, 'group_name' => $r->group_name, 'amount' => round($r->balance, 2)])->values(),
            'total_income'  => $totalIncome,
            'total_expense' => $totalExpense,
            'net_profit'    => $netProfit,
        ], 'P&L retrieved');
    }

    // ── Balance Sheet ─────────────────────────────────────────────────────
    public function balanceSheet(Request $request): JsonResponse
    {
        $asOf = $request->get('as_of', now()->toDateString());

        $balances = DB::table('gld_transactions as t')
            ->join('gl_accounts as a', 'a.code', '=', 't.account_code')
            ->join('gl_account_groups as g', 'g.id', '=', 'a.group_id')
            ->join('gl_account_classes as c', 'c.id', '=', 'g.class_id')
            ->whereIn('c.class_type', ['Assets', 'Liabilities', 'Equity'])
            ->where('t.tran_date', '<=', $asOf)
            ->select(
                't.account_code',
                'a.name as account_name',
                'g.name as group_name',
                'c.class_type',
                DB::raw('SUM(t.amount) as balance')
            )
            ->groupBy('t.account_code', 'a.name', 'g.name', 'c.class_type')
            ->orderBy('c.class_type')
            ->orderBy('t.account_code')
            ->get();

        $assets      = $balances->where('class_type', 'Assets');
        $liabilities = $balances->where('class_type', 'Liabilities');
        $equity      = $balances->where('class_type', 'Equity');

        $mapRow = fn($r) => ['account_code' => $r->account_code, 'account_name' => $r->account_name, 'group_name' => $r->group_name, 'amount' => round($r->balance, 2)];

        $totalAssets      = round($assets->sum('balance'),      2);
        $totalLiabilities = round(-$liabilities->sum('balance'), 2);
        $totalEquity      = round(-$equity->sum('balance'),      2);

        return ApiResponse::success([
            'assets'             => $assets->map($mapRow)->values(),
            'liabilities'        => $liabilities->map($mapRow)->values(),
            'equity'             => $equity->map($mapRow)->values(),
            'total_assets'       => $totalAssets,
            'total_liabilities'  => $totalLiabilities,
            'total_equity'       => $totalEquity,
        ], 'Balance sheet retrieved');
    }

    // ── General Ledger Listing ────────────────────────────────────────────
    public function glListing(Request $request): JsonResponse
    {
        $from    = $request->get('date_from');
        $to      = $request->get('date_to');
        $account = $request->get('account_code');

        $rows = DB::table('gld_transactions as t')
            ->leftJoin('gl_accounts as a', 'a.code', '=', 't.account_code')
            ->select(
                't.id', 't.tran_date', 't.account_code', 'a.name as account_name',
                't.reference', 't.narration', 't.type',
                DB::raw('CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END as debit'),
                DB::raw('CASE WHEN t.amount < 0 THEN ABS(t.amount) ELSE 0 END as credit'),
                't.amount'
            )
            ->when($from,    fn($q) => $q->where('t.tran_date', '>=', $from))
            ->when($to,      fn($q) => $q->where('t.tran_date', '<=', $to))
            ->when($account, fn($q) => $q->where('t.account_code', $account))
            ->orderBy('t.account_code')
            ->orderBy('t.tran_date')
            ->orderBy('t.id')
            ->limit(2000)
            ->get();

        return ApiResponse::success([
            'rows'         => $rows,
            'total_debit'  => round($rows->sum('debit'),  2),
            'total_credit' => round($rows->sum('credit'), 2),
        ], 'GL listing retrieved');
    }

    // ── Journal Listing ───────────────────────────────────────────────────
    public function journalListing(Request $request): JsonResponse
    {
        $from = $request->get('date_from');
        $to   = $request->get('date_to');

        // Fetch individual lines
        $lines = DB::table('gld_transactions as t')
            ->leftJoin('gl_accounts as a', 'a.code', '=', 't.account_code')
            ->select(
                't.trans_no', 't.type', 't.tran_date', 't.reference',
                't.account_code', 'a.name as account_name',
                't.narration', 't.amount', 't.created_by'
            )
            ->when($from, fn($q) => $q->where('t.tran_date', '>=', $from))
            ->when($to,   fn($q) => $q->where('t.tran_date', '<=', $to))
            ->orderBy('t.tran_date')
            ->orderBy('t.trans_no')
            ->orderBy('t.type')
            ->limit(3000)
            ->get();

        // Group into journals
        $journals = $lines->groupBy(fn($l) => "{$l->type}-{$l->trans_no}")
            ->map(function ($entries) {
                $first = $entries->first();
                return [
                    'trans_no'    => $first->trans_no,
                    'type'        => $first->type,
                    'tran_date'   => $first->tran_date,
                    'reference'   => $first->reference,
                    'created_by'  => $first->created_by,
                    'total_debit' => round($entries->where('amount', '>', 0)->sum('amount'), 2),
                    'total_credit'=> round($entries->where('amount', '<', 0)->sum(fn($e) => abs($e->amount)), 2),
                    'lines'       => $entries->map(fn($e) => [
                        'account_code' => $e->account_code,
                        'account_name' => $e->account_name ?? $e->account_code,
                        'narration'    => $e->narration,
                        'debit'        => $e->amount > 0 ? round($e->amount, 2) : 0,
                        'credit'       => $e->amount < 0 ? round(abs($e->amount), 2) : 0,
                    ])->values(),
                ];
            })->values();

        return ApiResponse::success([
            'journals'     => $journals,
            'total_debit'  => round($lines->where('amount', '>', 0)->sum('amount'), 2),
            'total_credit' => round($lines->where('amount', '<', 0)->sum(fn($l) => abs($l->amount)), 2),
        ], 'Journal listing retrieved');
    }

    // ── Form data (account list for filters) ─────────────────────────────
    public function formData(): JsonResponse
    {
        $accounts = DB::table('gl_accounts')
            ->where('inactive', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return ApiResponse::success(['accounts' => $accounts], 'Form data loaded');
    }
}
