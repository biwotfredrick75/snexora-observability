<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\GlPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PettyCashController extends Controller
{
    private const GL_TYPE = 60; // petty cash

    // ── Form data ─────────────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        $pettyCashAccounts = DB::table('gl_accounts')
            ->join('gl_account_groups', 'gl_accounts.group_id', '=', 'gl_account_groups.id')
            ->where('gl_account_groups.code', '1021') // bank / cash group
            ->where('gl_accounts.inactive', false)
            ->orderBy('gl_accounts.code')
            ->get(['gl_accounts.code', 'gl_accounts.name']);

        $expenseAccounts = DB::table('gl_accounts')
            ->where('inactive', false)
            ->orderBy('code')
            ->get(['code', 'name']);

        $users = DB::table('users')
            ->where('inactive', false)
            ->orderBy('real_name')
            ->get(['user_id', 'real_name']);

        return ApiResponse::success(compact('pettyCashAccounts', 'expenseAccounts', 'users'));
    }

    // ── List ──────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('petty_cash_requests')
            ->orderByDesc('request_date')
            ->orderByDesc('id');

        if ($request->filled('status'))       $q->where('status', $request->status);
        if ($request->filled('from'))         $q->where('request_date', '>=', $request->from);
        if ($request->filled('to'))           $q->where('request_date', '<=', $request->to);
        if ($request->filled('requested_by')) $q->where('requested_by', $request->requested_by);
        if ($request->filled('search'))
            $q->where('purpose', 'like', '%'.$request->search.'%');

        return ApiResponse::success($q->limit(500)->get());
    }

    // ── Create request ────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_date'            => 'required|date',
            'purpose'                 => 'required|string|max:200',
            'description'             => 'nullable|string',
            'amount_requested'        => 'required|numeric|min:0.01',
            'expense_account_code'    => 'required|string',
            'petty_cash_account_code' => 'required|string',
        ]);

        $requestNo = $this->nextRequestNo();
        $userId    = $request->user()?->user_id ?? 'system';

        $id = DB::table('petty_cash_requests')->insertGetId([
            ...$data,
            'request_no'   => $requestNo,
            'requested_by' => $userId,
            'status'       => 'pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return ApiResponse::created(
            DB::table('petty_cash_requests')->find($id),
            'Petty cash request submitted'
        );
    }

    // ── Approve / Reject ──────────────────────────────────────────────────────
    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:approved,rejected',
            'notes'  => 'nullable|string|max:500',
        ]);

        $rec = DB::table('petty_cash_requests')->find($id);
        if (! $rec) return ApiResponse::notFound('Request not found');
        if ($rec->status !== 'pending')
            return ApiResponse::validationError(['status' => ['Request is not pending']]);

        DB::table('petty_cash_requests')->where('id', $id)->update([
            'status'         => $data['action'],
            'approved_by'    => $request->user()?->user_id ?? 'system',
            'approved_at'    => now(),
            'approval_notes' => $data['notes'] ?? null,
            'updated_at'     => now(),
        ]);

        return ApiResponse::success(null, 'Request '.$data['action']);
    }

    // ── Disburse ──────────────────────────────────────────────────────────────
    public function disburse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount_disbursed' => 'required|numeric|min:0.01',
        ]);

        $rec = DB::table('petty_cash_requests')->find($id);
        if (! $rec) return ApiResponse::notFound('Request not found');
        if ($rec->status !== 'approved')
            return ApiResponse::validationError(['status' => ['Request must be approved before disbursement']]);

        $userId = $request->user()?->user_id ?? 'system';
        $transNo = $this->nextTransNo();

        DB::transaction(function () use ($rec, $data, $userId, $transNo, $id) {
            // GL: DR expense account, CR petty cash fund
            $date = now()->toDateString();
            GlPostingService::post([
                [
                    'trans_no'   => $transNo,
                    'type'       => self::GL_TYPE,
                    'tran_date'  => $date,
                    'account_code' => $rec->expense_account_code,
                    'reference'  => $rec->request_no,
                    'narration'  => 'Petty cash disbursement: '.$rec->purpose,
                    'amount'     => $data['amount_disbursed'],  // debit = positive
                    'created_by' => $userId,
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'trans_no'   => $transNo,
                    'type'       => self::GL_TYPE,
                    'tran_date'  => $date,
                    'account_code' => $rec->petty_cash_account_code,
                    'reference'  => $rec->request_no,
                    'narration'  => 'Petty cash disbursement: '.$rec->purpose,
                    'amount'     => -$data['amount_disbursed'], // credit = negative
                    'created_by' => $userId,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            DB::table('petty_cash_requests')->where('id', $id)->update([
                'status'          => 'disbursed',
                'disbursed_by'    => $userId,
                'disbursed_at'    => now(),
                'amount_disbursed'=> $data['amount_disbursed'],
                'updated_at'      => now(),
            ]);
        });

        return ApiResponse::success(null, 'Cash disbursed and GL posted');
    }

    // ── Retire ────────────────────────────────────────────────────────────────
    public function retire(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'actual_amount_spent' => 'required|numeric|min:0',
            'receipt_reference'   => 'nullable|string|max:100',
            'retirement_notes'    => 'nullable|string',
        ]);

        $rec = DB::table('petty_cash_requests')->find($id);
        if (! $rec) return ApiResponse::notFound('Request not found');
        if ($rec->status !== 'disbursed')
            return ApiResponse::validationError(['status' => ['Request must be disbursed before retirement']]);

        $userId      = $request->user()?->user_id ?? 'system';
        $disbursed   = (float) $rec->amount_disbursed;
        $actual      = (float) $data['actual_amount_spent'];
        $returned    = max(0, $disbursed - $actual);

        DB::transaction(function () use ($rec, $data, $userId, $disbursed, $actual, $returned, $id) {
            // If underspent, reverse the excess: DR petty cash fund, CR expense account
            if ($returned > 0.001) {
                $transNo = $this->nextTransNo();
                $date    = now()->toDateString();
                GlPostingService::post([
                    [
                        'trans_no'    => $transNo,
                        'type'        => self::GL_TYPE,
                        'tran_date'   => $date,
                        'account_code'=> $rec->petty_cash_account_code,
                        'reference'   => $rec->request_no,
                        'narration'   => 'Petty cash return: '.$rec->purpose,
                        'amount'      => $returned,  // debit fund (money back in)
                        'created_by'  => $userId,
                        'created_at'  => now(), 'updated_at' => now(),
                    ],
                    [
                        'trans_no'    => $transNo,
                        'type'        => self::GL_TYPE,
                        'tran_date'   => $date,
                        'account_code'=> $rec->expense_account_code,
                        'reference'   => $rec->request_no,
                        'narration'   => 'Petty cash return: '.$rec->purpose,
                        'amount'      => -$returned, // credit expense (reduce)
                        'created_by'  => $userId,
                        'created_at'  => now(), 'updated_at' => now(),
                    ],
                ]);
            }

            DB::table('petty_cash_requests')->where('id', $id)->update([
                'status'              => 'retired',
                'retired_by'          => $userId,
                'retired_at'          => now(),
                'actual_amount_spent' => $actual,
                'amount_returned'     => $returned,
                'receipt_reference'   => $data['receipt_reference'] ?? null,
                'retirement_notes'    => $data['retirement_notes'] ?? null,
                'updated_at'          => now(),
            ]);
        });

        return ApiResponse::success(null, 'Request retired successfully');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function nextRequestNo(): string
    {
        $max = DB::table('petty_cash_requests')
            ->where('request_no', 'like', 'PC-%')
            ->max(DB::raw("CAST(SUBSTRING(request_no, 4) AS UNSIGNED)"));
        return 'PC-'.str_pad(($max ?? 0) + 1, 6, '0', STR_PAD_LEFT);
    }

    private function nextTransNo(): int
    {
        return (int) DB::table('gld_transactions')
            ->where('type', self::GL_TYPE)
            ->max('trans_no') + 1;
    }
}
