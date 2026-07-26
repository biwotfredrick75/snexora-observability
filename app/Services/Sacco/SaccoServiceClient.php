<?php

namespace App\Services\Sacco;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * REST client for the standalone nexora-sacco Go service, which owns all
 * SACCO domain data (members/shares/savings/loans/GL) going forward. This
 * mirrors App\Services\Etims\EtimsService's calling convention exactly:
 * company_preferences override first, config default fallback, API key
 * auth, JSON in/out.
 */
class SaccoServiceClient
{
    private string $gatewayUrl;
    private string $apiKey;

    public function __construct()
    {
        $prefs = DB::table('company_preferences')->first();

        $this->gatewayUrl = rtrim($prefs?->sacco_gateway_url ?: config('sacco.gateway_url', 'http://localhost:8090'), '/');
        $this->apiKey     = $prefs?->sacco_api_key ?: config('sacco.api_key', 'dev-key-change-me');
    }

    // ── Members ────────────────────────────────────────────────────────────

    public function getMemberByExternalRef(string $refType, string $refId): ?array
    {
        $result = $this->call('GET', 'members', ['external_ref_type' => $refType, 'external_ref_id' => $refId]);
        $rows = $result['data'] ?? [];

        return $rows[0] ?? null;
    }

    public function listMembers(array $filters = []): array
    {
        return $this->call('GET', 'members', $filters)['data'] ?? [];
    }

    public function createMember(array $payload): array
    {
        return $this->call('POST', 'members', $payload)['data'] ?? [];
    }

    public function getMember(string $memberId): ?array
    {
        return $this->call('GET', "members/{$memberId}")['data'] ?? null;
    }

    public function updateMember(string $memberId, array $payload): ?array
    {
        return $this->call('PATCH', "members/{$memberId}", $payload)['data'] ?? null;
    }

    public function linkMember(string $memberId, string $refType, string $refId): array
    {
        return $this->call('POST', 'members/link', [
            'member_id' => $memberId, 'external_ref_type' => $refType, 'external_ref_id' => $refId,
        ])['data'] ?? [];
    }

    public function getBalanceSummary(string $memberId): ?array
    {
        return $this->call('GET', "members/{$memberId}/balance-summary")['data'] ?? null;
    }

    // ── Savings ────────────────────────────────────────────────────────────

    public function deposit(string $accountId, float $amount, string $reference = ''): array
    {
        return $this->call('POST', "savings-accounts/{$accountId}/deposit", ['amount' => $amount, 'reference' => $reference])['data'] ?? [];
    }

    public function withdraw(string $accountId, float $amount, string $reference = ''): array
    {
        return $this->call('POST', "savings-accounts/{$accountId}/withdraw", ['amount' => $amount, 'reference' => $reference])['data'] ?? [];
    }

    /** Org-wide savings accounts, each with interest_rate_pct joined in from its product. */
    public function listSavingsAccounts(array $filters = []): array
    {
        return $this->call('GET', 'savings-accounts', $filters)['data'] ?? [];
    }

    /** Org-wide share accounts, each with a computed `value` (shares_held * nominal_value_per_share). */
    public function listShareAccounts(array $filters = []): array
    {
        return $this->call('GET', 'share-accounts', $filters)['data'] ?? [];
    }

    public function purchaseShares(string $accountId, int $sharesQty): array
    {
        return $this->call('POST', "share-accounts/{$accountId}/purchase", ['shares_qty' => $sharesQty])['data'] ?? [];
    }

    /** Buys as many whole shares as `amount` (KES) covers at the product's nominal value. */
    public function purchaseSharesByAmount(string $accountId, float $amount): array
    {
        return $this->call('POST', "share-accounts/{$accountId}/purchase", ['amount' => $amount])['data'] ?? [];
    }

    // ── Loans ──────────────────────────────────────────────────────────────

    public function applyLoan(string $memberId, string $productId, float $principal, int $termMonths): array
    {
        return $this->call('POST', 'loans', [
            'member_id' => $memberId, 'product_id' => $productId,
            'principal_amount' => $principal, 'term_months' => $termMonths,
        ])['data'] ?? [];
    }

    public function approveLoan(string $loanId): array
    {
        return $this->call('POST', "loans/{$loanId}/approve")['data'] ?? [];
    }

    public function rejectLoan(string $loanId): array
    {
        return $this->call('POST', "loans/{$loanId}/reject")['data'] ?? [];
    }

    public function disburseLoan(string $loanId, string $method = 'cash'): array
    {
        return $this->call('POST', "loans/{$loanId}/disburse", ['disbursement_method' => $method])['data'] ?? [];
    }

    public function repayLoan(string $loanId, float $amount, string $paidVia = 'cash'): array
    {
        return $this->call('POST', "loans/{$loanId}/repay", ['amount' => $amount, 'paid_via' => $paidVia])['data'] ?? [];
    }

    public function listLoans(array $filters = []): array
    {
        return $this->call('GET', 'loans', $filters)['data'] ?? [];
    }

    public function listLoanProducts(): array
    {
        return $this->call('GET', 'products/loans')['data'] ?? [];
    }

    public function createLoanProduct(array $payload): ?array
    {
        return $this->call('POST', 'products/loans', $payload)['data'] ?? null;
    }

    public function getLoan(string $loanId): ?array
    {
        return $this->call('GET', "loans/{$loanId}")['data'] ?? null;
    }

    /** @return array{rows: array<int, array<string, mixed>>, total: float} */
    public function listRepayments(array $filters = []): array
    {
        $result = $this->call('GET', 'loans/repayments', $filters)['data'] ?? null;

        return $result ?? ['rows' => [], 'total' => 0];
    }

    // ── Checkoff (milk-payroll deduction integration) ─────────────────────

    /**
     * @return array<int, array{external_ref_id: string, amount: float, loan_no: string, installment_no: int, source_ref: string}>
     */
    public function postCheckoffForPeriod(int $periodMonth, int $periodYear): array
    {
        return $this->call('POST', 'integrations/checkoff/post-period', [
            'period_month' => $periodMonth, 'period_year' => $periodYear,
        ])['data'] ?? [];
    }

    // ── Financials ─────────────────────────────────────────────────────────

    public function trialBalance(?string $asOf = null): array
    {
        return $this->call('GET', 'gl/trial-balance', $asOf ? ['as_of' => $asOf] : [])['data'] ?? [];
    }

    public function glExport(string $accountCode = '', string $from = '', string $to = ''): array
    {
        return $this->call('GET', 'gl/export', array_filter([
            'account_code' => $accountCode, 'from' => $from, 'to' => $to,
        ]))['data'] ?? [];
    }

    public function dashboardSummary(): ?array
    {
        return $this->call('GET', 'dashboard/summary')['data'] ?? null;
    }

    // ── Internal ───────────────────────────────────────────────────────────

    private function call(string $method, string $path, array $params = []): array
    {
        try {
            $request = Http::withHeaders(['X-API-Key' => $this->apiKey])->timeout(30);
            $url = "{$this->gatewayUrl}/api/v1/{$path}";

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $params),
                'PATCH' => $request->patch($url, $params),
                default => $request->post($url, $params),
            };

            if ($response->failed()) {
                Log::error("SACCO gateway error [{$method} {$path}]: ".$response->body());

                return ['success' => false, 'data' => null, 'message' => $response->json('message') ?? 'gateway error'];
            }

            return $response->json() ?? ['success' => false, 'data' => null];
        } catch (\Throwable $e) {
            Log::error("SACCO gateway error [{$method} {$path}]: ".$e->getMessage());

            return ['success' => false, 'data' => null, 'message' => $e->getMessage()];
        }
    }
}
