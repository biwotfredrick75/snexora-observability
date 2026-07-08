<?php

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\PayrollEmployeeComponent;
use App\Models\PayrollItem;
use App\Models\PayrollPayComponent;
use App\Models\PayrollPeriod;
use App\Models\PayrollPosting;
use Illuminate\Support\Facades\DB;

class PayrollGenerationService
{
    // GL type code — distinct from the FrontAccounting-style codes used by
    // Sales/Purchases (see GlInquiryController::typeLabels). 50 = Payroll.
    public const GL_TYPE = 50;

    public function __construct(private PayrollStatutoryService $statutory) {}

    /**
     * Generate (or regenerate) a payroll period from active employees.
     * Auto postings are wiped and recreated; manual adjustments on each
     * item (manual_allowances/manual_deductions) are preserved.
     */
    public function generate(PayrollPeriod $period): PayrollPeriod
    {
        DB::transaction(function () use ($period) {
            $period->postings()->where('is_auto', true)->delete();

            $employees = Employee::where('status', 'active')->get();
            $activeIds = $employees->pluck('id')->all();
            $period->items()->whereNotIn('employee_id', $activeIds)->delete();

            $totals = ['gross' => 0, 'paye' => 0, 'shif' => 0, 'nssf' => 0, 'housing_levy' => 0, 'deductions' => 0, 'net' => 0];

            foreach ($employees as $employee) {
                $row = $this->processEmployee($period, $employee);
                $totals['gross']        += $row['gross_pay'];
                $totals['paye']         += $row['paye'];
                $totals['shif']         += $row['shif'];
                $totals['nssf']         += $row['nssf'];
                $totals['housing_levy'] += $row['housing_levy'];
                $totals['deductions']   += $row['other_deductions'];
                $totals['net']          += $row['net_pay'];
            }

            $period->update([
                'status'             => 'generated',
                'total_gross'        => round($totals['gross'], 2),
                'total_paye'         => round($totals['paye'], 2),
                'total_shif'         => round($totals['shif'], 2),
                'total_nssf'         => round($totals['nssf'], 2),
                'total_housing_levy' => round($totals['housing_levy'], 2),
                'total_deductions'   => round($totals['deductions'], 2),
                'total_net'          => round($totals['net'], 2),
            ]);
        });

        return $period->fresh(['items.employee.department', 'items.employee.jobTitle']);
    }

    private function processEmployee(PayrollPeriod $period, Employee $employee): array
    {
        $basicSalary = (float) $employee->basic_salary;

        // component_id => amount, aggregated across the company-wide catalogue and
        // this employee's individual recurring/one-time items, then written as a
        // single posting per component below.
        $allowanceAmounts = [];
        $deductionAmounts = [];

        foreach ($this->activeComponents('allowance') as $component) {
            $allowanceAmounts[$component->id] = ($allowanceAmounts[$component->id] ?? 0) + $this->componentAmount($component, $basicSalary);
        }
        foreach ($this->activeComponents('deduction', statutory: false) as $component) {
            $deductionAmounts[$component->id] = ($deductionAmounts[$component->id] ?? 0) + $this->componentAmount($component, $basicSalary);
        }

        foreach ($this->eligibleEmployeeComponents($employee, $period) as $eItem) {
            $bucket = $eItem->component->category === 'allowance' ? 'allowanceAmounts' : 'deductionAmounts';
            ${$bucket}[$eItem->component_id] = (${$bucket}[$eItem->component_id] ?? 0) + (float) $eItem->amount;
        }

        $autoAllowances = 0.0;
        foreach ($allowanceAmounts as $componentId => $amount) {
            $amount = round($amount, 2);
            PayrollPosting::updateOrCreate(
                ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'component_id' => $componentId],
                ['amount' => $amount, 'is_auto' => true]
            );
            $autoAllowances += $amount;
        }

        $item = PayrollItem::firstOrNew([
            'payroll_period_id' => $period->id,
            'employee_id'       => $employee->id,
        ]);
        $manualAllowances = $item->exists ? (float) ($item->manual_allowances ?? 0) : 0;
        $manualDeductions = $item->exists ? (float) ($item->manual_deductions ?? 0) : 0;

        $otherAllowances = round($autoAllowances, 2);
        $grossPay = round($basicSalary + $otherAllowances + $manualAllowances, 2);

        $statutory = $this->statutory->computeAll($grossPay);

        // Persist the statutory deductions as postings too, so they show up
        // alongside manual postings and can be GL-mapped per component.
        foreach (['statutory_paye' => $statutory['paye'], 'statutory_shif' => $statutory['shif'],
                  'statutory_nssf' => $statutory['nssf'], 'statutory_housing_levy' => $statutory['housing_levy']] as $type => $amount) {
            $component = $this->statutoryComponent($type);
            if (! $component) continue;
            PayrollPosting::updateOrCreate(
                ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'component_id' => $component->id],
                ['amount' => $amount, 'is_auto' => true]
            );
        }

        // External agrovet/service-provider (ESP) sales not yet deducted from this employee.
        // Folded into the same component-amount map so it's posted (and summed) exactly
        // like any other deduction; its component is deliberately inactive — see
        // espDeductionComponent() — so it doesn't get double-picked-up by the
        // activeComponents() loop above.
        $espComponent = $this->espDeductionComponent();
        $espDeduction = round((float) DB::table('esp_farmer_sales')
            ->where('party_type', 'employee')
            ->where('party_id', $employee->id)
            ->where('party_deducted', false)
            ->where('status', '!=', 'void')
            ->sum('total_amount'), 2);

        if ($espDeduction > 0) {
            $deductionAmounts[$espComponent->id] = ($deductionAmounts[$espComponent->id] ?? 0) + $espDeduction;
        }

        $autoDeductions = 0.0;
        foreach ($deductionAmounts as $componentId => $amount) {
            $amount = round($amount, 2);
            PayrollPosting::updateOrCreate(
                ['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'component_id' => $componentId],
                ['amount' => $amount, 'is_auto' => true]
            );
            $autoDeductions += $amount;
        }

        $otherDeductions = round($autoDeductions, 2);
        $statutoryTotal  = round($statutory['paye'] + $statutory['shif'] + $statutory['nssf'] + $statutory['housing_levy'], 2);
        $netPay          = round($grossPay - $statutoryTotal - $otherDeductions - $manualDeductions, 2);

        $item->fill([
            'basic_salary'       => $basicSalary,
            'gross_pay'          => $grossPay,
            'taxable_pay'        => $statutory['taxable_pay'],
            'paye'               => $statutory['paye'],
            'shif'               => $statutory['shif'],
            'nssf'               => $statutory['nssf'],
            'housing_levy'       => $statutory['housing_levy'],
            'other_allowances'   => $otherAllowances,
            'manual_allowances'  => $manualAllowances,
            'other_deductions'   => $otherDeductions,
            'manual_deductions'  => $manualDeductions,
            'net_pay'            => max(0, $netPay),
        ]);
        if (! $item->exists) {
            $item->payment_status = 'pending';
        }
        $item->save();

        return [
            'gross_pay'        => $grossPay,
            'paye'             => $statutory['paye'],
            'shif'             => $statutory['shif'],
            'nssf'             => $statutory['nssf'],
            'housing_levy'     => $statutory['housing_levy'],
            'other_deductions' => $otherDeductions + $manualDeductions,
            'net_pay'          => max(0, $netPay),
        ];
    }

    private array $componentCache = [];

    private function activeComponents(string $category, ?bool $statutory = null)
    {
        $key = $category . '|' . var_export($statutory, true);
        if (isset($this->componentCache[$key])) return $this->componentCache[$key];

        $query = PayrollPayComponent::where('active', true)->where('category', $category)->orderBy('sort_order');
        if ($statutory !== null) $query->where('is_statutory', $statutory);
        if ($category === 'allowance') $query->where('is_statutory', false);

        return $this->componentCache[$key] = $query->get()->all();
    }

    /** This employee's individual pay items (loans, one-off bonuses, …) active for this period. */
    private function eligibleEmployeeComponents(Employee $employee, PayrollPeriod $period)
    {
        return PayrollEmployeeComponent::with('component')
            ->where('employee_id', $employee->id)
            ->eligibleFor($period->period_start, $period->period_end)
            ->get();
    }

    private function statutoryComponent(string $computationType): ?PayrollPayComponent
    {
        $key = 'statutory:' . $computationType;
        if (array_key_exists($key, $this->componentCache)) return $this->componentCache[$key];

        return $this->componentCache[$key] = PayrollPayComponent::where('computation_type', $computationType)->first();
    }

    /**
     * Deliberately `active = false` — its amount is looked up per-employee from
     * unsettled ESP sales, not from `default_amount`/`percentage`, so it must be
     * excluded from the generic activeComponents('deduction') auto-loop above.
     */
    private function espDeductionComponent(): PayrollPayComponent
    {
        if (isset($this->componentCache['esp_deduction'])) return $this->componentCache['esp_deduction'];

        return $this->componentCache['esp_deduction'] = PayrollPayComponent::firstOrCreate(
            ['name' => 'External Provider Purchases'],
            ['category' => 'deduction', 'computation_type' => 'fixed', 'default_amount' => 0,
             'is_statutory' => false, 'active' => false, 'sort_order' => 900]
        );
    }

    private function componentAmount(PayrollPayComponent $component, float $basicSalary): float
    {
        return match ($component->computation_type) {
            'percentage_of_basic' => round($basicSalary * ((float) $component->percentage / 100), 2),
            default               => round((float) $component->default_amount, 2),
        };
    }

    public function approve(PayrollPeriod $period, int $userId): PayrollPeriod
    {
        if ($period->status !== 'generated') {
            throw new \RuntimeException('Only a generated payroll period can be approved.');
        }

        $period->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);

        return $period->fresh(['items.employee']);
    }

    /**
     * Post the approved period to the GL:
     *   DR Salary Expense (gross)
     *   CR PAYE Payable / SHIF Payable / NSSF Payable / Housing Levy Payable
     *   CR Net Salaries Payable (net pay — settled later via the bank file)
     *
     * Allowance/deduction line items beyond the four statutory ones are folded
     * into gross/net for GL purposes in this version, rather than broken out
     * per component account.
     */
    public function postToGl(PayrollPeriod $period, int $userId): PayrollPeriod
    {
        if ($period->status !== 'approved') {
            throw new \RuntimeException('Only an approved payroll period can be posted to the GL.');
        }

        $glSetting = GlSetting::first();
        $salaryExpenseAccount = $glSetting?->items_cogs_account ?: '600010';
        $payeAccount          = $this->resolveComponentAccount('statutory_paye', '230010');
        $shifAccount          = $this->resolveComponentAccount('statutory_shif', '230011');
        $nssfAccount          = $this->resolveComponentAccount('statutory_nssf', '230012');
        $housingAccount       = $this->resolveComponentAccount('statutory_housing_levy', '230013');
        $netPayAccount        = '230014';

        DB::transaction(function () use ($period, $userId, $salaryExpenseAccount, $payeAccount, $shifAccount, $nssfAccount, $housingAccount, $netPayAccount) {
            $tranDate = $period->period_end->toDateString();
            $ref      = $period->ref_no;

            GldTransaction::create([
                'trans_no' => $period->id, 'type' => self::GL_TYPE, 'tran_date' => $tranDate,
                'account_code' => $salaryExpenseAccount, 'reference' => $ref,
                'narration' => "Salary expense — {$ref}", 'amount' => (float) $period->total_gross,
                'created_by' => $userId,
            ]);

            if ((float) $period->total_paye > 0) {
                GldTransaction::create([
                    'trans_no' => $period->id, 'type' => self::GL_TYPE, 'tran_date' => $tranDate,
                    'account_code' => $payeAccount, 'reference' => $ref,
                    'narration' => "PAYE payable — {$ref}", 'amount' => -(float) $period->total_paye,
                    'created_by' => $userId,
                ]);
            }
            if ((float) $period->total_shif > 0) {
                GldTransaction::create([
                    'trans_no' => $period->id, 'type' => self::GL_TYPE, 'tran_date' => $tranDate,
                    'account_code' => $shifAccount, 'reference' => $ref,
                    'narration' => "SHIF payable — {$ref}", 'amount' => -(float) $period->total_shif,
                    'created_by' => $userId,
                ]);
            }
            if ((float) $period->total_nssf > 0) {
                GldTransaction::create([
                    'trans_no' => $period->id, 'type' => self::GL_TYPE, 'tran_date' => $tranDate,
                    'account_code' => $nssfAccount, 'reference' => $ref,
                    'narration' => "NSSF payable — {$ref}", 'amount' => -(float) $period->total_nssf,
                    'created_by' => $userId,
                ]);
            }
            if ((float) $period->total_housing_levy > 0) {
                GldTransaction::create([
                    'trans_no' => $period->id, 'type' => self::GL_TYPE, 'tran_date' => $tranDate,
                    'account_code' => $housingAccount, 'reference' => $ref,
                    'narration' => "Housing levy payable — {$ref}", 'amount' => -(float) $period->total_housing_levy,
                    'created_by' => $userId,
                ]);
            }

            $otherDeductionsTotal = round((float) $period->total_deductions, 2);
            $netPayable = round(
                (float) $period->total_gross - (float) $period->total_paye - (float) $period->total_shif
                - (float) $period->total_nssf - (float) $period->total_housing_levy - $otherDeductionsTotal,
                2
            );

            GldTransaction::create([
                'trans_no' => $period->id, 'type' => self::GL_TYPE, 'tran_date' => $tranDate,
                'account_code' => $netPayAccount, 'reference' => $ref,
                'narration' => "Net salaries payable — {$ref}", 'amount' => -$netPayable,
                'created_by' => $userId,
            ]);

            $period->update(['status' => 'posted', 'posted_by' => $userId, 'posted_at' => now()]);

            $this->markEspSalesDeducted($period);
            $this->consumeEmployeeComponentInstallments($period);
        });

        return $period->fresh();
    }

    /**
     * Consume one installment for every employee pay item with a fixed installment
     * count that was included in this period (re-derives eligibility the same way
     * generate() did — safe as long as nothing edited eligibility between generate
     * and post, same assumption the rest of this flow already makes). Indefinite
     * items (no total_installments) are left untouched; they run until deactivated.
     */
    private function consumeEmployeeComponentInstallments(PayrollPeriod $period): void
    {
        $items = PayrollEmployeeComponent::whereNotNull('total_installments')
            ->eligibleFor($period->period_start, $period->period_end)
            ->get();

        foreach ($items as $item) {
            $used = $item->installments_used + 1;
            $item->update([
                'installments_used' => $used,
                'active'            => $used < $item->total_installments,
            ]);
        }
    }

    /**
     * Mark, per employee, the oldest not-yet-deducted ESP sales up to the amount
     * that was posted for the "External Provider Purchases" component in this
     * period — mirrors the oldest-first settlement pattern used elsewhere (see
     * EspController::postSettlement) and deliberately excludes any ESP sale
     * recorded after generate() ran (it will simply be picked up next period).
     */
    private function markEspSalesDeducted(PayrollPeriod $period): void
    {
        $component = $this->espDeductionComponent();
        $tranDate  = $period->period_end->toDateString();

        $postings = PayrollPosting::where('payroll_period_id', $period->id)
            ->where('component_id', $component->id)
            ->where('amount', '>', 0)
            ->get(['employee_id', 'amount']);

        foreach ($postings as $posting) {
            $remaining = (float) $posting->amount;
            $sales = DB::table('esp_farmer_sales')
                ->where('party_type', 'employee')
                ->where('party_id', $posting->employee_id)
                ->where('party_deducted', false)
                ->where('status', '!=', 'void')
                ->orderBy('id')
                ->get(['id', 'total_amount']);

            foreach ($sales as $sale) {
                if ($remaining <= 0.005) break;
                DB::table('esp_farmer_sales')->where('id', $sale->id)->update([
                    'party_deducted'     => true,
                    'party_deducted_at'  => $tranDate,
                    'party_deducted_ref' => $period->ref_no,
                    'updated_at'         => now(),
                ]);
                $remaining -= (float) $sale->total_amount;
            }
        }
    }

    private function resolveComponentAccount(string $computationType, string $fallback): string
    {
        $component = $this->statutoryComponent($computationType);
        return $component?->glAccount?->code ?: $fallback;
    }

    public function bankFileRows(PayrollPeriod $period): array
    {
        return $period->items()
            ->with('employee')
            ->where('payment_status', 'pending')
            ->get()
            ->map(fn ($item) => [
                $item->employee->bank_account ?? '',
                $item->employee->bank_name ?? '',
                $item->employee->full_name,
                number_format($item->net_pay, 2, '.', ''),
                "Salary {$period->ref_no}",
            ])
            ->toArray();
    }
}
