<?php

namespace App\Http\Controllers\Sacco;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Farmer;
use App\Services\Sacco\SaccoServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Thin proxy to the standalone nexora-sacco Go service, which now owns all
 * SACCO domain data (members/shares/savings/loans/GL) -- same role
 * EtimsController plays for the etims-gateway. Local sacco_* tables/models
 * are deprecated (kept readable, no longer written to); see the class
 * docblocks on those models.
 *
 * IDs returned by the Go service are UUID strings, not the auto-increment
 * ints the old local-table version returned -- any frontend code that
 * parsed sacco IDs as integers needs to switch to opaque string IDs.
 */
class SaccoController extends Controller
{
    public function __construct(private SaccoServiceClient $client) {}

    // ── Members ──────────────────────────────────────────────────────────────

    public function indexMembers(Request $request): JsonResponse
    {
        $filters = array_filter([
            'status' => $request->query('status'),
            'search' => $request->query('search'),
        ]);
        $members = $this->client->listMembers($filters);

        return ApiResponse::success($this->hydrateFarmers($members), 'Members retrieved');
    }

    public function storeMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'farmer_id' => 'required|integer|exists:farmers,id',
            'join_date' => 'required|date',
        ]);

        $farmer = Farmer::find($data['farmer_id']);

        $member = $this->client->createMember([
            'full_name'  => $farmer->full_name,
            'phone'      => $farmer->phone,
            'join_date'  => $data['join_date'],
            'external_ref_type' => 'farmer',
            'external_ref_id'   => (string) $farmer->id,
        ]);

        return ApiResponse::created($this->hydrateFarmer($member), 'Member registered');
    }

    public function showMember(string $id): JsonResponse
    {
        $member = $this->client->getMember($id);
        if (! $member) {
            return ApiResponse::notFound('Member not found');
        }

        $summary = $this->client->getBalanceSummary($id);

        return ApiResponse::success(array_merge($this->hydrateFarmer($member), [
            'balance_summary' => $summary,
        ]), 'Member retrieved');
    }

    public function updateMember(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'sometimes|string|in:active,dormant,exited,deceased',
        ]);

        $member = $this->client->updateMember($id, $data);
        if (! $member) {
            return ApiResponse::notFound('Member not found');
        }

        return ApiResponse::updated($member, 'Member updated');
    }

    /** @param array<int, array<string, mixed>> $members */
    private function hydrateFarmers(array $members): array
    {
        return array_map(fn ($m) => $this->hydrateFarmer($m), $members);
    }

    /** Joins in the Farmer record the Go service only knows as an opaque external_ref_id. */
    private function hydrateFarmer(array $member): array
    {
        if (($member['external_ref_type'] ?? null) === 'farmer' && ! empty($member['external_ref_id'])) {
            $member['farmer'] = Farmer::select('id', 'farmer_no', 'full_name', 'phone')
                ->find((int) $member['external_ref_id']);
        }

        return $member;
    }

    // ── Accounts (savings / shares) ─────────────────────────────────────────

    /**
     * Combined savings + shares listing, merged and hydrated with farmer
     * info the same way the pre-rewrite indexAccounts() did -- the Go
     * service tracks these as two separate account types with no shared
     * "all accounts" endpoint, so the merge happens here.
     */
    public function indexAccounts(Request $request): JsonResponse
    {
        $memberId = $request->query('member_id');
        $accountType = $request->query('account_type');
        $filters = $memberId ? ['member_id' => $memberId] : [];

        $accounts = [];

        if ($accountType !== 'shares') {
            foreach ($this->client->listSavingsAccounts($filters) as $a) {
                $rate = (float) ($a['interest_rate_pct'] ?? 0);
                $accounts[] = array_merge($a, [
                    'account_type' => 'savings',
                    'projected_annual_earnings' => round((float) $a['balance'] * $rate / 100, 2),
                    'savings_interest_rate_pct' => $rate,
                ]);
            }
        }
        if ($accountType !== 'savings') {
            foreach ($this->client->listShareAccounts($filters) as $a) {
                $accounts[] = array_merge($a, [
                    'account_type' => 'shares',
                    'balance' => $a['value'],
                    'redemption_value' => $a['value'],
                ]);
            }
        }

        $accounts = $this->hydrateMembers($accounts);

        return ApiResponse::success($accounts, 'Accounts retrieved');
    }

    /** Joins in each account's member (and that member's farmer) by member_id, batching lookups per unique member. */
    private function hydrateMembers(array $accounts): array
    {
        $memberCache = [];
        foreach ($accounts as &$acct) {
            $memberId = $acct['member_id'] ?? null;
            if (! $memberId) {
                continue;
            }
            if (! array_key_exists($memberId, $memberCache)) {
                $memberCache[$memberId] = $this->hydrateFarmer($this->client->getMember($memberId) ?? []);
            }
            $acct['member'] = $memberCache[$memberId];
        }

        return $accounts;
    }

    public function deposit(Request $request, string $account): JsonResponse
    {
        $data = $this->validateTransactionInput($request);

        if (($data['account_type'] ?? 'savings') === 'shares') {
            $result = $this->client->purchaseSharesByAmount($account, (float) $data['amount']);

            return ApiResponse::created($result, 'Share purchase recorded');
        }

        $result = $this->client->deposit($account, (float) $data['amount'], $data['narration'] ?? '');

        return ApiResponse::created($result, 'Deposit recorded');
    }

    public function withdraw(Request $request, string $account): JsonResponse
    {
        $data = $this->validateTransactionInput($request);

        if (($data['account_type'] ?? 'savings') === 'shares') {
            return ApiResponse::error('Only savings accounts support withdrawals', 422);
        }

        $result = $this->client->withdraw($account, (float) $data['amount'], $data['narration'] ?? '');

        return ApiResponse::updated($result, 'Withdrawal recorded');
    }

    private function validateTransactionInput(Request $request): array
    {
        return $request->validate([
            'amount'       => 'required|numeric|min:0.01',
            'account_type' => 'nullable|string|in:savings,shares',
            'narration'    => 'nullable|string|max:255',
            'reference'    => 'nullable|string|max:30',
        ]);
    }

    // ── Loan Products ────────────────────────────────────────────────────────

    public function indexLoanProducts(): JsonResponse
    {
        return ApiResponse::success($this->client->listLoanProducts(), 'Loan products retrieved');
    }

    public function storeLoanProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'                    => 'required|string|max:20',
            'name'                    => 'required|string|max:100',
            'interest_rate_pct'       => 'required|numeric|min:0|max:100',
            'max_term_months'         => 'required|integer|min:1',
            'max_savings_multiplier'  => 'nullable|numeric|min:0',
        ]);

        $product = $this->client->createLoanProduct($data);

        return ApiResponse::created($product, 'Loan product created');
    }

    // ── Loans ─────────────────────────────────────────────────────────────────

    public function indexLoans(Request $request): JsonResponse
    {
        $filters = array_filter([
            'member_id' => $request->query('member_id'),
            'status'    => $request->query('status'),
        ]);

        return ApiResponse::success($this->client->listLoans($filters), 'Loans retrieved');
    }

    public function storeLoanApplication(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id'        => 'required|string',
            'product_id'       => 'required|string',
            'principal_amount' => 'required|numeric|min:0.01',
            'term_months'      => 'required|integer|min:1',
        ]);

        $loan = $this->client->applyLoan($data['member_id'], $data['product_id'], (float) $data['principal_amount'], (int) $data['term_months']);

        if (empty($loan)) {
            return ApiResponse::validationError(['principal_amount' => ['Loan application rejected by the SACCO service -- check eligibility (term/savings multiplier).']]);
        }

        return ApiResponse::created($loan, 'Loan application submitted');
    }

    public function showLoan(string $id): JsonResponse
    {
        $loan = $this->client->getLoan($id);
        if (! $loan) {
            return ApiResponse::notFound('Loan not found');
        }

        return ApiResponse::success($loan, 'Loan retrieved');
    }

    public function approveLoan(string $id): JsonResponse
    {
        $loan = $this->client->approveLoan($id);

        return ApiResponse::updated($loan, 'Loan approved');
    }

    public function rejectLoan(string $id): JsonResponse
    {
        $loan = $this->client->rejectLoan($id);

        return ApiResponse::updated($loan, 'Loan rejected');
    }

    public function disburseLoan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['disbursement_method' => 'nullable|string|in:cash,bank,mobile_money']);

        $loan = $this->client->disburseLoan($id, $data['disbursement_method'] ?? 'cash');

        return ApiResponse::updated($loan, 'Loan disbursed');
    }

    // ── Repayments ────────────────────────────────────────────────────────────

    public function repayCash(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'amount'   => 'required|numeric|min:0.01',
            'paid_via' => 'required|string|in:cash,bank',
        ]);

        $repayment = $this->client->repayLoan($id, (float) $data['amount'], $data['paid_via']);

        return ApiResponse::updated($repayment, 'Repayment recorded');
    }

    /**
     * Calls the SACCO service's checkoff endpoint, then inserts the returned
     * rows into farmer_checkoff_entries here in Laravel -- the Go service
     * never touches Laravel's tables directly. Reuses the existing
     * (source_type, source_ref) unique index for the double-post guard, so
     * no schema change is needed on the Laravel side.
     */
    public function postCheckoffForPeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => 'required|integer|min:1|max:12',
            'year'  => 'required|integer|min:2000',
        ]);

        $results = $this->client->postCheckoffForPeriod((int) $data['month'], (int) $data['year']);

        $serviceId = DB::table('checkoff_services')->where('service_name', 'SACCO Loan Repayment')->value('id');
        if (! $serviceId) {
            $serviceId = DB::table('checkoff_services')->insertGetId([
                'service_name' => 'SACCO Loan Repayment',
                'service_type' => 'Deduction',
                'active'       => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $posted = 0;
        foreach ($results as $row) {
            $exists = DB::table('farmer_checkoff_entries')
                ->where('source_type', 'sacco_loan')
                ->where('source_ref', $row['source_ref'])
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table('farmer_checkoff_entries')->insert([
                'farmer_id'    => (int) $row['external_ref_id'],
                'month'        => $data['month'],
                'year'         => $data['year'],
                'service_id'   => $serviceId,
                'service_name' => 'SACCO Loan Repayment',
                'amount'       => $row['amount'],
                'note'         => "Loan {$row['loan_no']}, installment #{$row['installment_no']}",
                'source_type'  => 'sacco_loan',
                'source_ref'   => $row['source_ref'],
                'deducted'     => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $posted++;
        }

        return ApiResponse::success(['count' => $posted], "{$posted} checkoff entries posted");
    }

    /**
     * Consolidated repayment/recovery history across all loans -- every
     * paid installment (cash, bank, or milk_checkoff), newest first.
     * Complements the per-loan schedule shown on showLoan().
     */
    public function repaymentHistory(Request $request): JsonResponse
    {
        $filters = array_filter([
            'member_id' => $request->query('member_id'),
            'loan_id'   => $request->query('loan_id'),
            'paid_via'  => $request->query('paid_via'),
            'from'      => $request->query('from'),
            'to'        => $request->query('to'),
        ]);

        $result = $this->client->listRepayments($filters);

        $memberCache = [];
        $rows = array_map(function ($r) use (&$memberCache) {
            $memberId = $r['member_id'] ?? null;
            if ($memberId && ! array_key_exists($memberId, $memberCache)) {
                $memberCache[$memberId] = $this->hydrateFarmer($this->client->getMember($memberId) ?? []);
            }

            return [
                'id' => $r['id'],
                'paid_date' => $r['paid_date'],
                'loan' => [
                    'loan_no' => $r['loan_no'],
                    'member' => $memberId ? $memberCache[$memberId] : null,
                ],
                'installment_no' => $r['installment_no'],
                'principal_due' => $r['principal_component'],
                'interest_due' => $r['interest_component'],
                'amount_paid' => $r['amount'],
                'paid_via' => $r['paid_via'],
            ];
        }, $result['rows'] ?? []);

        return ApiResponse::success([
            'rows' => $rows,
            'total' => round((float) ($result['total'] ?? 0), 2),
        ], 'Repayment history retrieved');
    }

    // ── Dashboard / Financials ────────────────────────────────────────────────

    public function dashboard(): JsonResponse
    {
        $summary = $this->client->dashboardSummary();

        return ApiResponse::success($summary ?? [
            'total_members' => 0, 'total_savings' => 0, 'total_shares' => 0,
            'loans_outstanding' => 0, 'active_loans' => 0, 'pending_loans' => 0,
            'total_recovered' => 0, 'total_interest_earned' => 0,
        ], 'Dashboard data retrieved');
    }
}
