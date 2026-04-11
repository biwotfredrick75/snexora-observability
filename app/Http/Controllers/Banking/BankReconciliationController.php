<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankReconciliationController extends Controller
{
    /**
     * GET /api/banking/reconciliation/transactions
     * Returns all GL transactions for a bank account, with reconciled flag.
     */
    public function transactions(Request $request): JsonResponse
    {
        $request->validate([
            'account_code'   => 'required|string',
            'statement_date' => 'required|date',
        ]);

        $code    = $request->account_code;
        $stmDate = $request->statement_date;
        $from    = $request->input('from');
        $to      = $request->input('to', $stmDate);

        // Get or create the reconciliation session
        $recon = DB::table('bank_reconciliations')
            ->where('account_code', $code)
            ->where('statement_date', $stmDate)
            ->first();

        if (! $recon) {
            $reconId = DB::table('bank_reconciliations')->insertGetId([
                'account_code'    => $code,
                'statement_date'  => $stmDate,
                'statement_balance' => 0,
                'status'          => 'open',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $recon = DB::table('bank_reconciliations')->find($reconId);
        }

        // Ticked line IDs for this session
        $reconItemIds = DB::table('bank_reconciliation_items')
            ->where('reconciliation_id', $recon->id)
            ->pluck('gld_transaction_id')
            ->toArray();

        $q = DB::table('gld_transactions')
            ->where('account_code', $code)
            ->orderBy('tran_date')
            ->orderBy('trans_no');

        if ($from) $q->where('tran_date', '>=', $from);
        if ($to)   $q->where('tran_date', '<=', $to);

        $rows = $q->get()->map(function ($r) use ($reconItemIds) {
            $r = (array) $r;
            $r['reconciled'] = in_array($r['id'], $reconItemIds);
            $r['debit']  = $r['amount'] > 0 ? $r['amount'] : 0;
            $r['credit'] = $r['amount'] < 0 ? abs($r['amount']) : 0;
            return $r;
        });

        // Book balance = sum of ALL transactions up to statement_date
        $bookBalance = DB::table('gld_transactions')
            ->where('account_code', $code)
            ->where('tran_date', '<=', $stmDate)
            ->sum('amount');

        // Cleared balance = sum of reconciled items
        $clearedBalance = $rows->filter(fn ($r) => $r['reconciled'])->sum(fn ($r) => $r['amount']);

        return ApiResponse::success([
            'reconciliation'  => $recon,
            'rows'            => $rows->values(),
            'book_balance'    => (float) $bookBalance,
            'cleared_balance' => (float) $clearedBalance,
            'difference'      => (float) $recon->statement_balance - (float) $clearedBalance,
        ]);
    }

    /**
     * POST /api/banking/reconciliation/toggle
     * Tick or untick a transaction line.
     */
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reconciliation_id'  => 'required|integer',
            'gld_transaction_id' => 'required|integer',
            'reconciled'         => 'required|boolean',
        ]);

        $existing = DB::table('bank_reconciliation_items')
            ->where('reconciliation_id', $data['reconciliation_id'])
            ->where('gld_transaction_id', $data['gld_transaction_id'])
            ->first();

        if ($data['reconciled'] && ! $existing) {
            DB::table('bank_reconciliation_items')->insert([
                'reconciliation_id'  => $data['reconciliation_id'],
                'gld_transaction_id' => $data['gld_transaction_id'],
            ]);
        } elseif (! $data['reconciled'] && $existing) {
            DB::table('bank_reconciliation_items')
                ->where('reconciliation_id', $data['reconciliation_id'])
                ->where('gld_transaction_id', $data['gld_transaction_id'])
                ->delete();
        }

        return ApiResponse::success(null, 'Updated');
    }

    /**
     * POST /api/banking/reconciliation/update-balance
     * Save the statement balance.
     */
    public function updateBalance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reconciliation_id' => 'required|integer',
            'statement_balance' => 'required|numeric',
        ]);

        DB::table('bank_reconciliations')
            ->where('id', $data['reconciliation_id'])
            ->update(['statement_balance' => $data['statement_balance'], 'updated_at' => now()]);

        return ApiResponse::success(null, 'Balance updated');
    }

    /**
     * POST /api/banking/reconciliation/finalise
     * Mark reconciliation as complete.
     */
    public function finalise(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reconciliation_id' => 'required|integer',
        ]);

        DB::table('bank_reconciliations')
            ->where('id', $data['reconciliation_id'])
            ->update([
                'status'         => 'reconciled',
                'reconciled_by'  => $request->user()?->user_id ?? 'system',
                'reconciled_at'  => now()->toDateString(),
                'updated_at'     => now(),
            ]);

        return ApiResponse::success(null, 'Reconciliation finalised');
    }

    /**
     * GET /api/banking/reconciliation/history
     */
    public function history(Request $request): JsonResponse
    {
        $q = DB::table('bank_reconciliations')->orderByDesc('statement_date');
        if ($request->filled('account_code')) $q->where('account_code', $request->account_code);
        return ApiResponse::success($q->limit(100)->get());
    }
}
