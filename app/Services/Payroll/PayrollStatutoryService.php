<?php

namespace App\Services\Payroll;

/**
 * Kenyan statutory payroll deductions.
 *
 * Rates below reflect the law as of the 2023/2024 Finance Act cycle:
 *   - PAYE bands effective 1 Jul 2023 (Finance Act 2023)
 *   - NSSF Tier I/II fully phased in Feb 2024 (NSSF Act 2013, 5th schedule)
 *   - SHIF (replaced NHIF) 2.75% of gross, effective Oct 2024
 *   - Affordable Housing Levy 1.5% of gross, effective 2024
 *
 * Statutory rates change with each Finance Act — review these figures
 * against the current KRA/NSSF/SHIF guidance before relying on them for
 * a live payroll run.
 */
class PayrollStatutoryService
{
    private const PERSONAL_RELIEF = 2400.00; // KES per month

    private const PAYE_BANDS = [
        // [upper_bound, rate] — bands are cumulative, upper_bound = null means "and above"
        [24_000, 0.10],
        [32_333, 0.25],
        [500_000, 0.30],
        [800_000, 0.325],
        [null, 0.35],
    ];

    private const NSSF_TIER1_LIMIT = 8_000;   // Lower Earnings Limit
    private const NSSF_TIER2_LIMIT = 72_000;  // Upper Earnings Limit
    private const NSSF_RATE        = 0.06;

    private const SHIF_RATE = 0.0275;
    private const SHIF_MIN  = 300.00;

    private const HOUSING_LEVY_RATE = 0.015;

    public function nssf(float $grossPay): float
    {
        $pensionable = max(0, $grossPay);
        $tier1 = self::NSSF_RATE * min($pensionable, self::NSSF_TIER1_LIMIT);
        $tier2 = $pensionable > self::NSSF_TIER1_LIMIT
            ? self::NSSF_RATE * (min($pensionable, self::NSSF_TIER2_LIMIT) - self::NSSF_TIER1_LIMIT)
            : 0.0;

        return round($tier1 + $tier2, 2);
    }

    public function shif(float $grossPay): float
    {
        return round(max($grossPay * self::SHIF_RATE, self::SHIF_MIN), 2);
    }

    public function housingLevy(float $grossPay): float
    {
        return round($grossPay * self::HOUSING_LEVY_RATE, 2);
    }

    /**
     * @param float $taxablePay Gross pay less NSSF and Housing Levy (both tax-deductible
     *                          under current Finance Act guidance).
     */
    public function paye(float $taxablePay): float
    {
        $remaining = max(0, $taxablePay);
        $tax       = 0.0;
        $lowerBound = 0;

        foreach (self::PAYE_BANDS as [$upperBound, $rate]) {
            $bandWidth = $upperBound === null ? $remaining : min($remaining, $upperBound - $lowerBound);
            if ($bandWidth <= 0) break;

            $tax       += $bandWidth * $rate;
            $remaining -= $bandWidth;
            $lowerBound = $upperBound ?? $lowerBound;

            if ($remaining <= 0) break;
        }

        return round(max(0, $tax - self::PERSONAL_RELIEF), 2);
    }

    /**
     * Computes all four statutory deductions for one employee in one pass.
     *
     * @return array{nssf: float, housing_levy: float, shif: float, paye: float, taxable_pay: float}
     */
    public function computeAll(float $grossPay): array
    {
        $nssf        = $this->nssf($grossPay);
        $housingLevy = $this->housingLevy($grossPay);
        $shif        = $this->shif($grossPay);
        $taxablePay  = max(0, round($grossPay - $nssf - $housingLevy, 2));
        $paye        = $this->paye($taxablePay);

        return [
            'nssf'         => $nssf,
            'housing_levy' => $housingLevy,
            'shif'         => $shif,
            'taxable_pay'  => $taxablePay,
            'paye'         => $paye,
        ];
    }
}
