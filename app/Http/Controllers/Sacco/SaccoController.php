<?php

namespace App\Http\Controllers\Sacco;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GlSetting;
use App\Models\SaccoAccount;
use App\Models\SaccoLoan;
use App\Models\SaccoLoanGuarantor;
use App\Models\SaccoLoanProduct;
use App\Models\SaccoLoanRepaymentSchedule;
use App\Models\SaccoMember;
use App\Models\SaccoTransaction;
use App\Services\Sacco\LoanAmortizationService;
use App\Services\Sacco\SaccoGlPostingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaccoController extends Controller
{
    public function __construct(
        private LoanAmortizationService $amortization,
        private SaccoGlPostingService $gl,
    ) {}

    // ── Members ──────────────────────────────────────────────────────────────

    public function indexMembers(Request $request): JsonResponse
    {
        $query = SaccoMember::with(['farmer:id,farmer_no,full_name,phone', 'accounts']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q
                ->where('membership_no', 'like', "%{$s}%")
                ->orWhereHas('farmer', fn ($f) => $f
                    ->where('full_name', 'like', "%{$s}%")
                    ->orWhere('farmer_no', 'like', "%{$s}%")));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return ApiResponse::success($query->orderByDesc('id')->limit(200)->get(), 'Members retrieved');
    }

    public function storeMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'farmer_id' => 'required|integer|exists:farmers,id|unique:sacco_members,farmer_id',
            'join_date' => 'required|date',
        ]);

        $user = auth()->user()?->user_id ?? 'system';

        $member = DB::transaction(function () use ($data, $user) {
            $member = SaccoMember::create([
                'membership_no' => $this->nextMembershipNo(),
                'farmer_id'     => $data['farmer_id'],
                'join_date'     => $data['join_date'],
                'status'        => 'active',
                'created_by'    => $user,
            ]);

            // Every member gets exactly one savings and one shares account,
            // created here rather than lazily — the rest of the module
            // (deposits, loan eligibility checks) assumes both always exist.
            SaccoAccount::create([
                'member_id'    => $member->id,
                'account_type' => 'savings',
                'account_no'   => $member->membership_no . '-SAV',
            ]);
            SaccoAccount::create([
                'member_id'    => $member->id,
                'account_type' => 'shares',
                'account_no'   => $member->membership_no . '-SHR',
            ]);

            return $member;
        });

        return ApiResponse::created($member->load(['farmer', 'accounts']), 'Member registered');
    }

    public function showMember(int $id): JsonResponse
    {
        $member = SaccoMember::with([
            'farmer', 'accounts.transactions',
            'loans.product', 'loans.schedule', 'loans.guarantors.guarantor.farmer',
        ])->find($id);

        if (! $member) {
            return ApiResponse::notFound('Member not found');
        }

        $this->enrichAccounts($member->accounts);

        return ApiResponse::success($member, 'Member retrieved');
    }

    public function updateMember(Request $request, int $id): JsonResponse
    {
        $member = SaccoMember::find($id);
        if (! $member) {
            return ApiResponse::notFound('Member not found');
        }

        $data = $request->validate([
            'status' => 'sometimes|string|in:active,inactive,exited',
        ]);

        $member->update($data);

        return ApiResponse::updated($member, 'Member updated');
    }

    private function nextMembershipNo(): string
    {
        $count = SaccoMember::count();
        return 'SAC-' . str_pad((string) ($count + 1), 5, '0', STR_PAD_LEFT);
    }

    // ── Accounts (savings / shares) ─────────────────────────────────────────

    public function indexAccounts(Request $request): JsonResponse
    {
        $query = SaccoAccount::with('member.farmer:id,farmer_no,full_name');

        if ($request->filled('member_id')) {
            $query->where('member_id', $request->integer('member_id'));
        }
        if ($request->filled('account_type')) {
            $query->where('account_type', $request->account_type);
        }

        $accounts = $query->orderByDesc('id')->limit(200)->get();
        $this->enrichAccounts($accounts);

        return ApiResponse::success($accounts, 'Accounts retrieved');
    }

    /**
     * Appends a member-facing estimate to each account — not a real accrued
     * balance (no interest has actually been declared/posted anywhere):
     * savings gets a projected annual earnings figure at the configured
     * rate, shares gets what the member would receive if they sold at book
     * value (currently just their contributed balance — no per-share
     * appreciation model exists yet).
     */
    private function enrichAccounts($accounts): void
    {
        $rate = (float) (GlSetting::first()?->sacco_savings_interest_rate_pct ?? 0);
        foreach ($accounts as $acct) {
            if ($acct->account_type === 'savings') {
                $acct->projected_annual_earnings = round((float) $acct->balance * $rate / 100, 2);
                $acct->savings_interest_rate_pct = $rate;
            } else {
                $acct->redemption_value = (float) $acct->balance;
            }
        }
    }

    public function deposit(Request $request, int $account): JsonResponse
    {
        $acct = SaccoAccount::find($account);
        if (! $acct) {
            return ApiResponse::notFound('Account not found');
        }

        $data = $this->validateTransactionInput($request);
        $user = auth()->user()?->user_id ?? 'system';

        // Buying shares vs. depositing savings post to different GL accounts
        // and use different sacco_transactions.type values — the account's
        // own type determines which, the caller doesn't choose.
        $isShares = $acct->account_type === 'shares';
        $type     = $isShares ? 'share_purchase' : 'deposit';

        $txn = DB::transaction(function () use ($acct, $data, $type, $isShares, $user) {
            $txn = SaccoTransaction::create([
                'account_id'       => $acct->id,
                'type'             => $type,
                'amount'           => $data['amount'],
                'reference'        => $data['reference'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'narration'        => $data['narration'] ?? null,
                'source'           => $data['source'] ?? 'cash',
                'created_by'       => $user,
            ]);

            $acct->balance = round((float) $acct->balance + (float) $data['amount'], 2);
            $acct->save();

            $isShares
                ? $this->gl->postSharePurchase($txn, $user)
                : $this->gl->postSavingsDeposit($txn, $user);

            return $txn;
        });

        return ApiResponse::created(
            ['transaction' => $txn, 'balance' => $acct->fresh()->balance],
            ($isShares ? 'Share purchase' : 'Deposit') . ' recorded'
        );
    }

    public function withdraw(Request $request, int $account): JsonResponse
    {
        $acct = SaccoAccount::find($account);
        if (! $acct) {
            return ApiResponse::notFound('Account not found');
        }
        // Shares aren't freely withdrawable in a SACCO the way savings are —
        // surrendering shares is a separate, more controlled process not in
        // this module's v1 scope.
        if ($acct->account_type !== 'savings') {
            return ApiResponse::error('Only savings accounts support withdrawals', 422);
        }

        $data = $this->validateTransactionInput($request);

        if ((float) $data['amount'] > (float) $acct->balance + 0.005) {
            return ApiResponse::validationError([
                'amount' => ['Insufficient balance — available ' . round((float) $acct->balance, 2)],
            ]);
        }

        $user = auth()->user()?->user_id ?? 'system';

        $txn = DB::transaction(function () use ($acct, $data, $user) {
            $txn = SaccoTransaction::create([
                'account_id'       => $acct->id,
                'type'             => 'withdrawal',
                'amount'           => $data['amount'],
                'reference'        => $data['reference'] ?? null,
                'transaction_date' => $data['transaction_date'],
                'narration'        => $data['narration'] ?? null,
                'source'           => $data['source'] ?? 'cash',
                'created_by'       => $user,
            ]);

            $acct->balance = round((float) $acct->balance - (float) $data['amount'], 2);
            $acct->save();

            $this->gl->postSavingsWithdrawal($txn, $user);

            return $txn;
        });

        return ApiResponse::created(['transaction' => $txn, 'balance' => $acct->fresh()->balance], 'Withdrawal recorded');
    }

    private function validateTransactionInput(Request $request): array
    {
        return $request->validate([
            'amount'           => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'source'           => 'nullable|string|in:cash,bank,manual',
            'narration'        => 'nullable|string|max:255',
            'reference'        => 'nullable|string|max:30',
        ]);
    }

    // ── Loan Products ────────────────────────────────────────────────────────

    public function indexLoanProducts(): JsonResponse
    {
        return ApiResponse::success(SaccoLoanProduct::orderBy('name')->get(), 'Loan products retrieved');
    }

    public function storeLoanProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                    => 'required|string|max:100',
            'interest_rate_pct'       => 'required|numeric|min:0|max:100',
            'max_term_months'         => 'required|integer|min:1',
            'max_savings_multiplier'  => 'nullable|numeric|min:0',
            'status'                  => 'nullable|string|in:active,inactive',
        ]);

        $product = SaccoLoanProduct::create(array_merge($data, ['status' => $data['status'] ?? 'active']));

        return ApiResponse::created($product, 'Loan product created');
    }

    // ── Loans ─────────────────────────────────────────────────────────────────

    public function indexLoans(Request $request): JsonResponse
    {
        $query = SaccoLoan::with(['member.farmer:id,farmer_no,full_name', 'product']);

        if ($request->filled('member_id')) {
            $query->where('member_id', $request->integer('member_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return ApiResponse::success($query->orderByDesc('id')->limit(200)->get(), 'Loans retrieved');
    }

    public function storeLoanApplication(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id'                       => 'required|integer|exists:sacco_members,id',
            'product_id'                      => 'required|integer|exists:sacco_loan_products,id',
            'principal_amount'                => 'required|numeric|min:0.01',
            'term_months'                     => 'required|integer|min:1',
            'application_date'                => 'required|date',
            'guarantors'                      => 'nullable|array',
            'guarantors.*.member_id'          => 'required_with:guarantors|integer|exists:sacco_members,id',
            'guarantors.*.guaranteed_amount'  => 'required_with:guarantors|numeric|min:0',
        ]);

        $product = SaccoLoanProduct::findOrFail($data['product_id']);

        if ($data['term_months'] > $product->max_term_months) {
            return ApiResponse::validationError([
                'term_months' => ["Exceeds this product's maximum of {$product->max_term_months} months"],
            ]);
        }

        if ($product->max_savings_multiplier) {
            $savings = (float) (SaccoAccount::where('member_id', $data['member_id'])
                ->where('account_type', 'savings')->value('balance') ?? 0);
            $maxLoan = round($savings * (float) $product->max_savings_multiplier, 2);
            if ((float) $data['principal_amount'] > $maxLoan + 0.005) {
                return ApiResponse::validationError([
                    'principal_amount' => [
                        "Exceeds the maximum of {$maxLoan} ({$product->max_savings_multiplier}x savings balance of {$savings})",
                    ],
                ]);
            }
        }

        $user = auth()->user()?->user_id ?? 'system';

        $loan = DB::transaction(function () use ($data, $product, $user) {
            $loan = SaccoLoan::create([
                'loan_no'             => $this->nextLoanNo(),
                'member_id'           => $data['member_id'],
                'product_id'          => $product->id,
                'principal_amount'    => $data['principal_amount'],
                'interest_rate_pct'   => $product->interest_rate_pct,
                'term_months'         => $data['term_months'],
                'application_date'    => $data['application_date'],
                'status'              => 'pending',
                'outstanding_balance' => $data['principal_amount'],
                'created_by'          => $user,
            ]);

            foreach ($data['guarantors'] ?? [] as $g) {
                SaccoLoanGuarantor::create([
                    'loan_id'           => $loan->id,
                    'member_id'         => $g['member_id'],
                    'guaranteed_amount' => $g['guaranteed_amount'],
                ]);
            }

            return $loan;
        });

        return ApiResponse::created($loan->load(['guarantors.guarantor.farmer', 'member.farmer']), 'Loan application submitted');
    }

    public function showLoan(int $id): JsonResponse
    {
        $loan = SaccoLoan::with(['member.farmer', 'product', 'guarantors.guarantor.farmer', 'schedule'])->find($id);
        if (! $loan) {
            return ApiResponse::notFound('Loan not found');
        }

        return ApiResponse::success($loan, 'Loan retrieved');
    }

    public function approveLoan(int $id): JsonResponse
    {
        $loan = SaccoLoan::find($id);
        if (! $loan) {
            return ApiResponse::notFound('Loan not found');
        }
        if ($loan->status !== 'pending') {
            return ApiResponse::error('Only a pending loan can be approved', 422);
        }

        $loan->update([
            'status'      => 'approved',
            'approved_by' => auth()->user()?->user_id ?? 'system',
        ]);

        return ApiResponse::updated($loan, 'Loan approved');
    }

    public function rejectLoan(int $id): JsonResponse
    {
        $loan = SaccoLoan::find($id);
        if (! $loan) {
            return ApiResponse::notFound('Loan not found');
        }
        if (! in_array($loan->status, ['pending', 'approved'])) {
            return ApiResponse::error('Loan cannot be rejected from its current status', 422);
        }

        $loan->update(['status' => 'rejected']);

        return ApiResponse::updated($loan, 'Loan rejected');
    }

    public function disburseLoan(Request $request, int $id): JsonResponse
    {
        $loan = SaccoLoan::find($id);
        if (! $loan) {
            return ApiResponse::notFound('Loan not found');
        }
        if ($loan->status !== 'approved') {
            return ApiResponse::error('Loan must be approved before it can be disbursed', 422);
        }

        $data = $request->validate([
            'disbursement_date' => 'required|date',
        ]);

        $user = auth()->user()?->user_id ?? 'system';

        $loan = DB::transaction(function () use ($loan, $data, $user) {
            $rows = $this->amortization->buildSchedule(
                (float) $loan->principal_amount,
                (float) $loan->interest_rate_pct,
                $loan->term_months,
                Carbon::parse($data['disbursement_date']),
            );

            foreach ($rows as $row) {
                SaccoLoanRepaymentSchedule::create(array_merge($row, ['loan_id' => $loan->id]));
            }

            $loan->update([
                'status'             => 'disbursed',
                'disbursement_date'  => $data['disbursement_date'],
                'disbursed_by'       => $user,
            ]);

            $this->gl->postLoanDisbursement($loan, $user);

            return $loan;
        });

        return ApiResponse::updated($loan->load('schedule'), 'Loan disbursed');
    }

    // ── Repayments ────────────────────────────────────────────────────────────

    public function repayCash(Request $request, int $id): JsonResponse
    {
        $loan = SaccoLoan::find($id);
        if (! $loan) {
            return ApiResponse::notFound('Loan not found');
        }

        $data = $request->validate([
            'installment_id' => 'required|integer|exists:sacco_loan_repayment_schedule,id',
            'paid_date'       => 'required|date',
            'paid_via'        => 'required|string|in:cash,bank',
        ]);

        $installment = SaccoLoanRepaymentSchedule::where('loan_id', $loan->id)->find($data['installment_id']);
        if (! $installment) {
            return ApiResponse::notFound('Installment not found for this loan');
        }
        if ($installment->status === 'paid') {
            return ApiResponse::error('Installment already paid', 422);
        }

        $user = auth()->user()?->user_id ?? 'system';

        DB::transaction(function () use ($installment, $loan, $data, $user) {
            $this->settleInstallment($installment, $loan, $data['paid_via'], $data['paid_date'], $user);
        });

        return ApiResponse::updated($installment->fresh(), 'Repayment recorded');
    }

    /**
     * Posts installments due for a period into farmer_checkoff_entries
     * (reusing the same table MilkPayslipController::payslips() already
     * sums into net pay — no changes needed there) and settles them the
     * same way repayCash() does. Guarded against double-posting by
     * checkoff_posted_at — re-running for the same period is a no-op.
     */
    public function postCheckoffForPeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2000',
        ]);

        $user      = auth()->user()?->user_id ?? 'system';
        $periodEnd = Carbon::create($data['year'], $data['month'])->endOfMonth();

        $serviceId = DB::table('checkoff_services')->where('service_name', 'SACCO Loan Repayment')->value('id');
        if (! $serviceId) {
            $serviceId = DB::table('checkoff_services')->insertGetId([
                'service_name' => 'SACCO Loan Repayment',
                'service_type' => 'sacco_loan',
                'active'       => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $due = SaccoLoanRepaymentSchedule::with('loan.member')
            ->where('status', 'pending')
            ->whereNull('checkoff_posted_at')
            ->where('due_date', '<=', $periodEnd)
            ->get();

        $posted = [];

        DB::transaction(function () use ($due, $data, $serviceId, $user, $periodEnd, &$posted) {
            foreach ($due as $installment) {
                $loan     = $installment->loan;
                $farmerId = $loan->member->farmer_id;

                $exists = DB::table('farmer_checkoff_entries')
                    ->where('source_type', 'sacco_loan')
                    ->where('source_ref', (string) $installment->id)
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('farmer_checkoff_entries')->insert([
                    'farmer_id'    => $farmerId,
                    'month'        => $data['month'],
                    'year'         => $data['year'],
                    'service_id'   => $serviceId,
                    'service_name' => 'SACCO Loan Repayment',
                    'amount'       => $installment->total_due,
                    'note'         => "Loan {$loan->loan_no}, installment #{$installment->installment_no}",
                    'source_type'  => 'sacco_loan',
                    'source_ref'   => (string) $installment->id,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                $this->settleInstallment($installment, $loan, 'milk_checkoff', $periodEnd->toDateString(), $user, checkoffPosted: true);

                $posted[] = $installment->id;
            }
        });

        return ApiResponse::success(
            ['posted_installment_ids' => $posted, 'count' => count($posted)],
            count($posted) . ' checkoff entries posted'
        );
    }

    /** Shared by repayCash() and postCheckoffForPeriod() — marks an installment paid, updates the loan balance/status, and GL-posts. */
    private function settleInstallment(
        SaccoLoanRepaymentSchedule $installment,
        SaccoLoan $loan,
        string $paidVia,
        string $paidDate,
        string $user,
        bool $checkoffPosted = false,
    ): void {
        $installment->update([
            'amount_paid'        => $installment->total_due,
            'status'             => 'paid',
            'paid_via'           => $paidVia,
            'paid_date'          => $paidDate,
            'checkoff_posted_at' => $checkoffPosted ? now() : $installment->checkoff_posted_at,
        ]);

        $loan->outstanding_balance = round((float) $loan->outstanding_balance - (float) $installment->principal_due, 2);
        $hasPending = SaccoLoanRepaymentSchedule::where('loan_id', $loan->id)->where('status', 'pending')->exists();
        if ($loan->outstanding_balance <= 0.005 && ! $hasPending) {
            $loan->status = 'settled';
        }
        $loan->save();

        $this->gl->postLoanRepayment($installment->fresh(), $paidVia, $user);
    }

    private function nextLoanNo(): string
    {
        $count = SaccoLoan::count();
        return 'LN-' . str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    /**
     * Consolidated repayment/recovery history across all loans — every
     * paid installment (cash, bank, or milk_checkoff), newest first.
     * Complements the per-loan schedule shown on showLoan().
     */
    public function repaymentHistory(Request $request): JsonResponse
    {
        $query = SaccoLoanRepaymentSchedule::with(['loan.member.farmer:id,farmer_no,full_name', 'loan.product:id,name'])
            ->where('status', 'paid');

        if ($request->filled('member_id')) {
            $query->whereHas('loan', fn ($q) => $q->where('member_id', $request->integer('member_id')));
        }
        if ($request->filled('loan_id')) {
            $query->where('loan_id', $request->integer('loan_id'));
        }
        if ($request->filled('paid_via')) {
            $query->where('paid_via', $request->paid_via);
        }
        if ($request->filled('from')) {
            $query->where('paid_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('paid_date', '<=', $request->to);
        }

        $rows = $query->orderByDesc('paid_date')->orderByDesc('id')->limit(500)->get();

        return ApiResponse::success([
            'rows'  => $rows,
            'total' => round((float) $rows->sum('amount_paid'), 2),
        ], 'Repayment history retrieved');
    }

    public function dashboard(): JsonResponse
    {
        return ApiResponse::success([
            'total_members'         => SaccoMember::where('status', 'active')->count(),
            'total_savings'         => (float) SaccoAccount::where('account_type', 'savings')->sum('balance'),
            'total_shares'          => (float) SaccoAccount::where('account_type', 'shares')->sum('balance'),
            'loans_outstanding'     => (float) SaccoLoan::whereIn('status', ['disbursed'])->sum('outstanding_balance'),
            'active_loans'          => SaccoLoan::where('status', 'disbursed')->count(),
            'pending_loans'         => SaccoLoan::where('status', 'pending')->count(),
            // Recoveries — every installment ever collected, cash/bank/checkoff combined.
            'total_recovered'       => round((float) SaccoLoanRepaymentSchedule::where('status', 'paid')->sum('amount_paid'), 2),
            'total_interest_earned' => round((float) SaccoLoanRepaymentSchedule::where('status', 'paid')->sum('interest_due'), 2),
        ], 'Dashboard data retrieved');
    }
}
