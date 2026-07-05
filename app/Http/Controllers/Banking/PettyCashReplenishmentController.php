<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PettyCashFund;
use App\Models\PettyCashReplenishment;
use App\Models\PettyCashVoucher;
use App\Services\GlPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PettyCashReplenishmentController extends Controller
{
    private const GL_TYPE = 60;

    // GET /banking/petty-cash/replenishments
    public function index(Request $request): JsonResponse
    {
        $q = PettyCashReplenishment::with('fund:id,name,fund_code')
            ->orderByDesc('request_date')->orderByDesc('id');

        if ($request->filled('fund_id')) $q->where('fund_id', $request->fund_id);
        if ($request->filled('status'))  $q->where('status', $request->status);

        return ApiResponse::success($q->limit(200)->get());
    }

    // POST /banking/petty-cash/replenishments
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fund_id'           => 'required|exists:petty_cash_funds,id',
            'request_date'      => 'required|date',
            'bank_account_code' => 'nullable|string|max:20',
            'notes'             => 'nullable|string|max:1000',
        ]);

        $fund = PettyCashFund::find($data['fund_id']);

        $vouchers = PettyCashVoucher::where('fund_id', $fund->id)
            ->where('status', 'approved')
            ->where('replenished', false)
            ->get();

        if ($vouchers->isEmpty()) {
            return ApiResponse::validationError([
                'fund_id' => ['No approved, un-replenished vouchers for this fund'],
            ]);
        }

        $amountRequested = $vouchers->sum('amount');
        $userId          = $request->user()?->user_id ?? 'system';

        $repl = PettyCashReplenishment::create([
            'repl_no'           => $this->nextReplNo(),
            'fund_id'           => $fund->id,
            'request_date'      => $data['request_date'],
            'requested_by'      => $userId,
            'amount_requested'  => $amountRequested,
            'vouchers_count'    => $vouchers->count(),
            'bank_account_code' => $data['bank_account_code'] ?? null,
            'status'            => 'pending',
            'notes'             => $data['notes'] ?? null,
        ]);

        return ApiResponse::created($repl->load('fund:id,name,fund_code'), 'Replenishment request created');
    }

    // GET /banking/petty-cash/replenishments/{id}
    public function show(int $id): JsonResponse
    {
        $repl = PettyCashReplenishment::with('fund')->find($id);
        if (! $repl) return ApiResponse::notFound('Replenishment not found');

        $vouchers = PettyCashVoucher::where('fund_id', $repl->fund_id)
            ->where('status', 'approved')
            ->where('replenished', false)
            ->orderBy('voucher_date')
            ->get(['id', 'voucher_no', 'voucher_date', 'payee', 'description', 'amount', 'expense_account_code']);

        return ApiResponse::success([
            'replenishment' => $repl,
            'vouchers'      => $vouchers,
        ]);
    }

    // POST /banking/petty-cash/replenishments/{id}/approve
    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'bank_account_code' => 'nullable|string|max:20',
            'notes'             => 'nullable|string|max:500',
        ]);

        $repl = PettyCashReplenishment::find($id);
        if (! $repl) return ApiResponse::notFound('Replenishment not found');
        if ($repl->status !== 'pending') {
            return ApiResponse::validationError(['status' => ['Replenishment is not pending']]);
        }

        $userId = $request->user()?->user_id ?? 'system';

        $repl->update([
            'status'            => 'approved',
            'approved_by'       => $userId,
            'approved_at'       => now(),
            'bank_account_code' => $data['bank_account_code'] ?? $repl->bank_account_code,
            'notes'             => $data['notes'] ?? $repl->notes,
        ]);

        return ApiResponse::success(null, 'Replenishment approved');
    }

    // POST /banking/petty-cash/replenishments/{id}/confirm  (payment received; restores fund)
    public function confirm(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'payment_reference' => 'required|string|max:100',
            'payment_date'      => 'required|date',
        ]);

        $repl = PettyCashReplenishment::with('fund')->find($id);
        if (! $repl) return ApiResponse::notFound('Replenishment not found');
        if ($repl->status !== 'approved') {
            return ApiResponse::validationError(['status' => ['Replenishment must be approved before confirmation']]);
        }

        $fund    = $repl->fund;
        $userId  = $request->user()?->user_id ?? 'system';
        $bankAcc = $repl->bank_account_code;

        DB::transaction(function () use ($repl, $fund, $userId, $data, $bankAcc) {
            // GL: DR petty cash fund account, CR bank account
            if ($bankAcc) {
                $transNo = $this->nextTransNo();
                $date    = $data['payment_date'];

                GlPostingService::post([
                    [
                        'trans_no'     => $transNo,
                        'type'         => self::GL_TYPE,
                        'tran_date'    => $date,
                        'account_code' => $fund->gl_account_code,
                        'reference'    => $repl->repl_no,
                        'narration'    => 'Petty cash replenishment '.$repl->repl_no,
                        'amount'       => $repl->amount_requested,   // DR fund (restored)
                        'created_by'   => $userId,
                        'created_at'   => now(), 'updated_at' => now(),
                    ],
                    [
                        'trans_no'     => $transNo,
                        'type'         => self::GL_TYPE,
                        'tran_date'    => $date,
                        'account_code' => $bankAcc,
                        'reference'    => $repl->repl_no,
                        'narration'    => 'Petty cash replenishment '.$repl->repl_no,
                        'amount'       => -$repl->amount_requested,  // CR bank
                        'created_by'   => $userId,
                        'created_at'   => now(), 'updated_at' => now(),
                    ],
                ]);

                $repl->update(['gl_trans_no' => $transNo]);
            }

            // Mark vouchers as replenished
            PettyCashVoucher::where('fund_id', $fund->id)
                ->where('status', 'approved')
                ->where('replenished', false)
                ->update(['replenished' => true]);

            // Restore fund balance to imprest
            $fund->update(['current_balance' => $fund->imprest_amount]);

            $repl->update([
                'status'            => 'confirmed',
                'payment_reference' => $data['payment_reference'],
                'payment_date'      => $data['payment_date'],
                'confirmed_by'      => $userId,
                'confirmed_at'      => now(),
            ]);
        });

        return ApiResponse::success(null, 'Replenishment confirmed — fund restored to imprest amount');
    }

    private function nextReplNo(): string
    {
        $year = now()->year;
        $max  = DB::table('petty_cash_replenishments')
            ->where('repl_no', 'like', "REP%/{$year}")
            ->pluck('repl_no')
            ->map(fn ($n) => (int) substr($n, 3, max(0, strpos($n, '/') - 3)))
            ->max() ?? 0;
        return 'REP'.str_pad($max + 1, 5, '0', STR_PAD_LEFT).'/'.$year;
    }

    private function nextTransNo(): int
    {
        return (int) (DB::table('gld_transactions')
            ->where('type', self::GL_TYPE)
            ->max('trans_no') ?? 0) + 1;
    }
}
