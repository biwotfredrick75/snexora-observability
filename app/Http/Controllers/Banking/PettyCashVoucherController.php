<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PettyCashFund;
use App\Models\PettyCashVoucher;
use App\Services\GlPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PettyCashVoucherController extends Controller
{
    private const GL_TYPE = 60;

    // GET /banking/petty-cash/vouchers
    public function index(Request $request): JsonResponse
    {
        $q = PettyCashVoucher::with('fund:id,name,fund_code,currency')
            ->orderByDesc('voucher_date')
            ->orderByDesc('id');

        if ($request->filled('fund_id'))  $q->where('fund_id', $request->fund_id);
        if ($request->filled('status'))   $q->where('status', $request->status);
        if ($request->filled('from'))     $q->where('voucher_date', '>=', $request->from);
        if ($request->filled('to'))       $q->where('voucher_date', '<=', $request->to);
        if ($request->filled('search')) {
            $s = '%'.$request->search.'%';
            $q->where(fn ($x) => $x->where('voucher_no', 'like', $s)
                ->orWhere('payee', 'like', $s)
                ->orWhere('description', 'like', $s));
        }

        return ApiResponse::success($q->limit(500)->get());
    }

    // POST /banking/petty-cash/vouchers
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fund_id'              => 'required|exists:petty_cash_funds,id',
            'voucher_date'         => 'required|date',
            'payee'                => 'required|string|max:150',
            'expense_account_code' => 'required|string|max:20',
            'description'          => 'required|string|max:500',
            'amount'               => 'required|numeric|min:0.01',
        ]);

        $fund = PettyCashFund::find($data['fund_id']);

        if ($fund->status !== 'active') {
            return ApiResponse::validationError(['fund_id' => ['Fund is not active']]);
        }

        if ($fund->transaction_limit && $data['amount'] > $fund->transaction_limit) {
            return ApiResponse::validationError([
                'amount' => ['Exceeds per-transaction limit of '.$fund->transaction_limit],
            ]);
        }

        $tier   = PettyCashVoucher::tierFor((float) $data['amount']);
        $userId = $request->user()?->user_id ?? 'system';

        if ($tier === 1 && $data['amount'] > $fund->current_balance) {
            return ApiResponse::validationError([
                'amount' => ['Insufficient fund balance ('.$fund->current_balance.')'],
            ]);
        }

        $status = ($tier === 1) ? 'approved' : 'pending';

        $voucher = DB::transaction(function () use ($data, $tier, $status, $userId, $fund) {
            $voucher = PettyCashVoucher::create([
                ...$data,
                'voucher_no'    => $this->nextVoucherNo(),
                'approval_tier' => $tier,
                'status'        => $status,
                'created_by'    => $userId,
                'approved_by'   => $status === 'approved' ? $userId : null,
                'approved_at'   => $status === 'approved' ? now() : null,
                'approval_notes'=> $status === 'approved' ? 'Tier 1 auto-approved' : null,
            ]);

            if ($status === 'approved') {
                $this->postVoucherGl($voucher, $fund, $userId);
                $fund->decrement('current_balance', $voucher->amount);
            }

            return $voucher;
        });

        return ApiResponse::created(
            $voucher->load('fund:id,name,fund_code'),
            'Voucher created'.(($tier === 1) ? ' and auto-approved' : ' — pending approval')
        );
    }

    // GET /banking/petty-cash/vouchers/{id}
    public function show(int $id): JsonResponse
    {
        $voucher = PettyCashVoucher::with('fund')->find($id);
        if (! $voucher) return ApiResponse::notFound('Voucher not found');

        return ApiResponse::success([
            'voucher'    => $voucher,
            'tier_label' => PettyCashVoucher::tierLabel($voucher->approval_tier),
        ]);
    }

    // POST /banking/petty-cash/vouchers/{id}/approve
    public function approve(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'approval_notes' => 'nullable|string|max:500',
        ]);

        $voucher = PettyCashVoucher::with('fund')->find($id);
        if (! $voucher) return ApiResponse::notFound('Voucher not found');
        if ($voucher->status !== 'pending') {
            return ApiResponse::validationError(['status' => ['Voucher is not pending approval']]);
        }

        $fund   = $voucher->fund;
        $userId = $request->user()?->user_id ?? 'system';

        if ($voucher->amount > $fund->current_balance) {
            return ApiResponse::validationError([
                'amount' => ['Insufficient fund balance ('.$fund->current_balance.')'],
            ]);
        }

        DB::transaction(function () use ($voucher, $fund, $userId, $data) {
            $voucher->update([
                'status'         => 'approved',
                'approved_by'    => $userId,
                'approved_at'    => now(),
                'approval_notes' => $data['approval_notes'] ?? null,
            ]);

            $this->postVoucherGl($voucher, $fund, $userId);
            $fund->decrement('current_balance', $voucher->amount);
        });

        return ApiResponse::success(null, 'Voucher approved');
    }

    // POST /banking/petty-cash/vouchers/{id}/reject
    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $voucher = PettyCashVoucher::find($id);
        if (! $voucher) return ApiResponse::notFound('Voucher not found');
        if ($voucher->status !== 'pending') {
            return ApiResponse::validationError(['status' => ['Voucher is not pending']]);
        }

        $voucher->update([
            'status'           => 'rejected',
            'rejected_by'      => $request->user()?->user_id ?? 'system',
            'rejected_at'      => now(),
            'rejection_reason' => $data['rejection_reason'],
        ]);

        return ApiResponse::success(null, 'Voucher rejected');
    }

    // POST /banking/petty-cash/vouchers/{id}/void
    public function void(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'void_reason' => 'required|string|max:500',
        ]);

        $voucher = PettyCashVoucher::with('fund')->find($id);
        if (! $voucher) return ApiResponse::notFound('Voucher not found');
        if (! in_array($voucher->status, ['pending', 'approved'])) {
            return ApiResponse::validationError(['status' => ['Cannot void a '.$voucher->status.' voucher']]);
        }

        $fund   = $voucher->fund;
        $userId = $request->user()?->user_id ?? 'system';

        DB::transaction(function () use ($voucher, $fund, $userId, $data) {
            $wasApproved = $voucher->status === 'approved';

            $voucher->update([
                'status'      => 'void',
                'voided_by'   => $userId,
                'voided_at'   => now(),
                'void_reason' => $data['void_reason'],
            ]);

            if ($wasApproved && $voucher->gl_trans_no) {
                $transNo = $this->nextTransNo();
                $date    = now()->toDateString();
                GlPostingService::post([
                    [
                        'trans_no'     => $transNo,
                        'type'         => self::GL_TYPE,
                        'tran_date'    => $date,
                        'account_code' => $fund->gl_account_code,
                        'reference'    => $voucher->voucher_no,
                        'narration'    => 'Void: '.$voucher->description,
                        'amount'       => $voucher->amount,
                        'created_by'   => $userId,
                        'created_at'   => now(), 'updated_at' => now(),
                    ],
                    [
                        'trans_no'     => $transNo,
                        'type'         => self::GL_TYPE,
                        'tran_date'    => $date,
                        'account_code' => $voucher->expense_account_code,
                        'reference'    => $voucher->voucher_no,
                        'narration'    => 'Void: '.$voucher->description,
                        'amount'       => -$voucher->amount,
                        'created_by'   => $userId,
                        'created_at'   => now(), 'updated_at' => now(),
                    ],
                ]);
                $fund->increment('current_balance', $voucher->amount);
            }
        });

        return ApiResponse::success(null, 'Voucher voided');
    }

    // ── GL helper ─────────────────────────────────────────────────────────────
    private function postVoucherGl(PettyCashVoucher $voucher, PettyCashFund $fund, string $userId): void
    {
        $transNo = $this->nextTransNo();
        $date    = $voucher->voucher_date ?? now()->toDateString();

        GlPostingService::post([
            [
                'trans_no'     => $transNo,
                'type'         => self::GL_TYPE,
                'tran_date'    => $date,
                'account_code' => $voucher->expense_account_code,
                'reference'    => $voucher->voucher_no,
                'narration'    => 'Petty cash: '.$voucher->description,
                'amount'       => $voucher->amount,   // DR expense
                'created_by'   => $userId,
                'created_at'   => now(), 'updated_at' => now(),
            ],
            [
                'trans_no'     => $transNo,
                'type'         => self::GL_TYPE,
                'tran_date'    => $date,
                'account_code' => $fund->gl_account_code,
                'reference'    => $voucher->voucher_no,
                'narration'    => 'Petty cash: '.$voucher->description,
                'amount'       => -$voucher->amount,  // CR petty cash fund
                'created_by'   => $userId,
                'created_at'   => now(), 'updated_at' => now(),
            ],
        ]);

        $voucher->update(['gl_trans_no' => $transNo]);
    }

    // ── Sequence helpers ──────────────────────────────────────────────────────
    private function nextVoucherNo(): string
    {
        $year = now()->year;
        $max  = DB::table('petty_cash_vouchers')
            ->where('voucher_no', 'like', "PCV%/{$year}")
            ->pluck('voucher_no')
            ->map(fn ($n) => (int) substr($n, 3, max(0, strpos($n, '/') - 3)))
            ->max() ?? 0;
        return 'PCV'.str_pad($max + 1, 5, '0', STR_PAD_LEFT).'/'.$year;
    }

    private function nextTransNo(): int
    {
        return (int) (DB::table('gld_transactions')
            ->where('type', self::GL_TYPE)
            ->max('trans_no') ?? 0) + 1;
    }
}
