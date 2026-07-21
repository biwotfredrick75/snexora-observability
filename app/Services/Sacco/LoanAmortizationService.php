<?php

namespace App\Services\Sacco;

use Carbon\Carbon;

/**
 * Pure calculation — builds a reducing-balance (declining-balance) monthly
 * amortization schedule. No DB writes; the caller persists the returned rows.
 *
 * Reducing balance, not flat-rate: flat-rate charges interest on the
 * original principal for the whole term, which systematically overcharges
 * a borrower as the loan matures — a real fairness concern for a
 * member-owned cooperative. Reducing balance is the standard SACCO/
 * microfinance approach in Kenya.
 */
class LoanAmortizationService
{
    /**
     * @return array<int, array{
     *   installment_no: int, due_date: string, principal_due: float,
     *   interest_due: float, total_due: float,
     * }>
     */
    public function buildSchedule(
        float $principal,
        float $annualRatePct,
        int $termMonths,
        Carbon $startDate,
    ): array {
        $monthlyRate = ($annualRatePct / 100) / 12;

        // Zero-interest edge case — split principal evenly, last row absorbs
        // the rounding remainder.
        if ($monthlyRate <= 0) {
            $flat = round($principal / $termMonths, 2);
            $rows = [];
            $balance = $principal;
            for ($i = 1; $i <= $termMonths; $i++) {
                $principalDue = $i === $termMonths ? round($balance, 2) : $flat;
                $balance -= $principalDue;
                $rows[] = [
                    'installment_no' => $i,
                    'due_date'       => $startDate->copy()->addMonthsNoOverflow($i)->toDateString(),
                    'principal_due'  => $principalDue,
                    'interest_due'   => 0.0,
                    'total_due'      => $principalDue,
                ];
            }
            return $rows;
        }

        $factor = (1 + $monthlyRate) ** $termMonths;
        $emi    = $principal * $monthlyRate * $factor / ($factor - 1);

        $rows    = [];
        $balance = $principal;

        for ($i = 1; $i <= $termMonths; $i++) {
            $interestDue = round($balance * $monthlyRate, 2);

            // Last installment: close the balance exactly, absorbing all
            // accumulated rounding drift, rather than trusting the formula.
            $principalDue = $i === $termMonths
                ? round($balance, 2)
                : round($emi - $interestDue, 2);

            $balance -= $principalDue;

            $rows[] = [
                'installment_no' => $i,
                'due_date'       => $startDate->copy()->addMonthsNoOverflow($i)->toDateString(),
                'principal_due'  => $principalDue,
                'interest_due'   => $interestDue,
                'total_due'      => round($principalDue + $interestDue, 2),
            ];
        }

        return $rows;
    }
}
