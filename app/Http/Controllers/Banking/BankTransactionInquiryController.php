<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankTransactionInquiryController extends Controller
{
    private static array $typeLabels = [
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
        50 => 'Payroll',
        60 => 'Petty Cash',
    ];

    public function formData(): JsonResponse
    {
        $bankAccounts = DB::table('gl_accounts')
            ->join('gl_account_groups', 'gl_accounts.group_id', '=', 'gl_account_groups.id')
            ->where('gl_account_groups.code', '1021')
            ->where('gl_accounts.inactive', false)
            ->orderBy('gl_accounts.code')
            ->get(['gl_accounts.code', 'gl_accounts.name']);

        return ApiResponse::success(compact('bankAccounts'));
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['account_code' => 'required|string']);

        $code = $request->account_code;
        $from = $request->input('from');
        $to   = $request->input('to', now()->toDateString());

        // Opening balance = sum of all transactions before $from
        $openingBalance = 0.0;
        if ($from) {
            $openingBalance = (float) DB::table('gld_transactions')
                ->where('account_code', $code)
                ->where('tran_date', '<', $from)
                ->sum('amount');
        }

        $q = DB::table('gld_transactions')
            ->where('account_code', $code)
            ->orderBy('tran_date')
            ->orderBy('id');

        if ($from) $q->where('tran_date', '>=', $from);
        if ($to)   $q->where('tran_date', '<=', $to);

        if ($request->filled('type')) $q->where('type', $request->type);

        $rows    = $q->limit(2000)->get();
        $running = $openingBalance;

        $rows = $rows->map(function ($r) use (&$running, &$seq) {
            $running += $r->amount;
            return [
                'id'              => $r->id,
                'type'            => $r->type,
                'type_label'      => self::$typeLabels[$r->type] ?? "Type {$r->type}",
                'trans_no'        => $r->trans_no,
                'tran_date'       => $r->tran_date,
                'reference'       => $r->reference,
                'narration'       => $r->narration,
                'debit'           => $r->amount > 0 ? (float) $r->amount : 0,
                'credit'          => $r->amount < 0 ? (float) abs($r->amount) : 0,
                'running_balance' => $running,
            ];
        });

        $closingBalance = $openingBalance + $rows->sum(fn ($r) => $r['debit'] - $r['credit']);

        return ApiResponse::success([
            'rows'            => $rows->values(),
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'total_debit'     => $rows->sum('debit'),
            'total_credit'    => $rows->sum('credit'),
            'type_labels'     => self::$typeLabels,
        ]);
    }
}
