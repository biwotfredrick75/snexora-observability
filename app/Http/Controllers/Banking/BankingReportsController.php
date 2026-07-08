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

    // ── Allocation Report ─────────────────────────────────────────────────
    public function allocationReport(Request $request): JsonResponse
    {
        $from = $request->get('date_from');
        $to   = $request->get('date_to');

        $q = DB::table('debtor_allocations as a')
            ->leftJoin('customers as c', 'c.debtor_no', '=', 'a.debtor_no')
            ->select(
                'a.id', 'a.debtor_no', 'c.name as customer_name',
                'a.source_type', 'a.source_no', 'a.inv_no',
                'a.amount', 'a.allocated_date', 'a.created_by'
            )
            ->orderByDesc('a.allocated_date');

        if ($from) $q->where('a.allocated_date', '>=', $from);
        if ($to)   $q->where('a.allocated_date', '<=', $to);
        if ($request->filled('debtor_no')) $q->where('a.debtor_no', $request->debtor_no);
        if ($request->filled('source_type')) $q->where('a.source_type', $request->source_type);

        $rows = $q->limit(2000)->get();

        $bySource = $rows->groupBy('source_type')->map(fn ($g) => round($g->sum('amount'), 2));

        return ApiResponse::success([
            'rows'          => $rows,
            'total_amount'  => round($rows->sum('amount'), 2),
            'by_source_type'=> $bySource,
        ], 'Allocation report retrieved');
    }

    // ── Cash Flow Statement (indirect method) ─────────────────────────────
    public function cashFlow(Request $request): JsonResponse
    {
        $from = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $request->get('date_to', now()->toDateString());

        // Net income for the period, from Income/Expense classified GL accounts.
        $pnl = DB::table('gld_transactions as t')
            ->join('gl_accounts as a', 'a.code', '=', 't.account_code')
            ->join('gl_account_groups as g', 'g.id', '=', 'a.group_id')
            ->join('gl_account_classes as c', 'c.id', '=', 'g.class_id')
            ->whereIn('c.class_type', ['Income', 'Expense'])
            ->whereBetween('t.tran_date', [$from, $to])
            ->select('c.class_type', DB::raw('SUM(t.amount) as balance'))
            ->groupBy('c.class_type')
            ->get()
            ->keyBy('class_type');

        $income     = -(float) ($pnl['Income']->balance ?? 0);
        $expense    = (float) ($pnl['Expense']->balance ?? 0);
        $netIncome  = round($income - $expense, 2);

        // Working-capital movement: change in each non-cash Asset/Liability
        // account's balance during the period (Operating activities, indirect
        // method). Bank/Cash accounts (group code 1021) are excluded here —
        // they're the actual "Cash" being reconciled, not a working-capital item.
        $bankGroupIds = DB::table('gl_account_groups')->where('code', '1021')->pluck('id');

        $movement = function (array $classTypes) use ($from, $to, $bankGroupIds) {
            return DB::table('gld_transactions as t')
                ->join('gl_accounts as a', 'a.code', '=', 't.account_code')
                ->join('gl_account_groups as g', 'g.id', '=', 'a.group_id')
                ->join('gl_account_classes as c', 'c.id', '=', 'g.class_id')
                ->whereIn('c.class_type', $classTypes)
                ->whereNotIn('a.group_id', $bankGroupIds)
                ->whereBetween('t.tran_date', [$from, $to])
                ->select('a.code', 'a.name', DB::raw('SUM(t.amount) as balance'))
                ->groupBy('a.code', 'a.name')
                ->havingRaw('SUM(t.amount) != 0')
                ->get();
        };

        $assetMoves     = $movement(['Assets']);
        $liabilityMoves = $movement(['Liabilities']);

        // An increase in a non-cash asset consumes cash (negative to cash flow);
        // liabilities are credit-normal (negative balance = increase), so an
        // increase (more negative raw balance) should ADD to cash.
        $liabilityCashEffect = round(-$liabilityMoves->sum('balance'), 2);
        $assetCashEffect     = round(-$assetMoves->sum('balance'), 2);

        $operatingCash = round($netIncome + $assetCashEffect + $liabilityCashEffect, 2);

        // Net change in actual cash/bank accounts over the period (should tie
        // out to operatingCash + investing + financing; we don't yet classify
        // investing/financing separately, so it's shown as a check figure).
        $cashMovement = (float) DB::table('gld_transactions as t')
            ->join('gl_accounts as a', 'a.code', '=', 't.account_code')
            ->whereIn('a.group_id', $bankGroupIds)
            ->whereBetween('t.tran_date', [$from, $to])
            ->sum('t.amount');

        return ApiResponse::success([
            'period'              => ['from' => $from, 'to' => $to],
            'net_income'          => $netIncome,
            'operating_adjustments' => [
                'asset_changes'     => $assetMoves->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'change' => -round($r->balance, 2)]),
                'liability_changes' => $liabilityMoves->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'change' => -round($r->balance, 2)]),
            ],
            'net_cash_from_operations' => $operatingCash,
            'net_change_in_cash'       => round($cashMovement, 2),
        ], 'Cash flow statement retrieved');
    }

    // ── Budget vs Actuals ──────────────────────────────────────────────────
    public function budgetVsActuals(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);

        $actuals = DB::table('gld_transactions as t')
            ->join('gl_accounts as a', 'a.code', '=', 't.account_code')
            ->join('gl_account_groups as g', 'g.id', '=', 'a.group_id')
            ->join('gl_account_classes as c', 'c.id', '=', 'g.class_id')
            ->whereIn('c.class_type', ['Income', 'Expense'])
            ->whereYear('t.tran_date', $year)
            ->select(
                'a.code', 'a.name', 'c.class_type',
                DB::raw('MONTH(t.tran_date) as month'),
                DB::raw('SUM(t.amount) as balance')
            )
            ->groupBy('a.code', 'a.name', 'c.class_type', DB::raw('MONTH(t.tran_date)'))
            ->get();

        $budgets = DB::table('gl_budgets')->where('year', $year)->get();

        // Merge every account that has either an actual or a budget figure this year.
        $accountKeys = $actuals->pluck('code')->merge($budgets->pluck('account_code'))->unique();
        $accountMeta = DB::table('gl_accounts as a')
            ->join('gl_account_groups as g', 'g.id', '=', 'a.group_id')
            ->join('gl_account_classes as c', 'c.id', '=', 'g.class_id')
            ->whereIn('a.code', $accountKeys)
            ->select('a.code', 'a.name', 'c.class_type')
            ->get()
            ->keyBy('code');

        $rows = $accountKeys->map(function ($code) use ($actuals, $budgets, $accountMeta) {
            $meta      = $accountMeta->get($code);
            $classType = $meta->class_type ?? 'Expense';
            $sign      = $classType === 'Income' ? -1 : 1; // income is credit-normal (negative)

            $actualTotal = $sign * (float) $actuals->where('code', $code)->sum('balance');
            $budgetTotal = (float) $budgets->where('account_code', $code)->sum('amount');

            return [
                'account_code' => $code,
                'account_name' => $meta->name ?? $code,
                'class_type'   => $classType,
                'budget'       => round($budgetTotal, 2),
                'actual'       => round($actualTotal, 2),
                'variance'     => round($actualTotal - $budgetTotal, 2),
            ];
        })->sortBy('account_code')->values();

        return ApiResponse::success([
            'year'          => $year,
            'rows'          => $rows,
            'total_budget'  => round($rows->sum('budget'), 2),
            'total_actual'  => round($rows->sum('actual'), 2),
        ], 'Budget vs actuals retrieved');
    }

    // ── Set/update a monthly budget figure for an account ─────────────────
    public function setBudget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_code' => 'required|string|max:20',
            'year'         => 'required|integer|min:2000|max:2100',
            'month'        => 'required|integer|min:1|max:12',
            'amount'       => 'required|numeric|min:0',
        ]);

        DB::table('gl_budgets')->updateOrInsert(
            ['account_code' => $data['account_code'], 'year' => $data['year'], 'month' => $data['month']],
            ['amount' => $data['amount'], 'created_by' => $request->user()?->user_id ?? 'system', 'updated_at' => now(), 'created_at' => now()]
        );

        return ApiResponse::success(null, 'Budget saved');
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
