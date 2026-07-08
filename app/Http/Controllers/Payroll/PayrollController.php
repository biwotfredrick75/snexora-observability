<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\PayrollEmployeeComponent;
use App\Models\PayrollItem;
use App\Models\PayrollPayComponent;
use App\Models\PayrollPeriod;
use App\Models\PayrollPosting;
use App\Services\Payroll\PayrollGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller
{
    public function __construct(protected PayrollGenerationService $service) {}

    // ── Periods ──────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = PayrollPeriod::withCount('items')->orderByDesc('period_start');
        if ($request->filled('status')) $query->where('status', $request->status);

        return ApiResponse::success($query->get(), 'Payroll periods retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'notes'        => 'nullable|string',
        ]);

        $overlap = PayrollPeriod::where(function ($q) use ($data) {
            $q->where('period_start', '<=', $data['period_end'])
              ->where('period_end',   '>=', $data['period_start']);
        })->exists();

        if ($overlap) {
            return ApiResponse::error('A payroll period already exists that covers part of this range.', 409);
        }

        $data['ref_no']     = PayrollPeriod::nextRefNo();
        $data['status']     = 'draft';
        $data['created_by'] = auth()->id();

        return ApiResponse::created(PayrollPeriod::create($data), 'Payroll period created');
    }

    public function show(int $id): JsonResponse
    {
        $period = PayrollPeriod::with(['items.employee.department', 'items.employee.jobTitle'])->findOrFail($id);
        return ApiResponse::success($period, 'Payroll period retrieved');
    }

    public function generate(int $id): JsonResponse
    {
        $period = PayrollPeriod::findOrFail($id);
        if (in_array($period->status, ['approved', 'posted'])) {
            return ApiResponse::error('Cannot regenerate an approved or posted period.', 409);
        }

        return ApiResponse::success($this->service->generate($period), 'Payroll period generated');
    }

    public function approve(int $id): JsonResponse
    {
        $period = PayrollPeriod::findOrFail($id);
        try {
            return ApiResponse::success($this->service->approve($period, auth()->id()), 'Payroll period approved');
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function postToGl(int $id): JsonResponse
    {
        $period = PayrollPeriod::findOrFail($id);
        try {
            return ApiResponse::success($this->service->postToGl($period, auth()->id()), 'Payroll period posted to GL');
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /** Manual adjustment to one employee's allowances/deductions for the period. */
    public function updateItem(Request $request, int $periodId, int $itemId): JsonResponse
    {
        $period = PayrollPeriod::findOrFail($periodId);
        if (in_array($period->status, ['approved', 'posted'])) {
            return ApiResponse::error('Cannot edit an approved or posted period.', 409);
        }

        $item = $period->items()->findOrFail($itemId);
        $data = $request->validate([
            'other_allowances' => 'nullable|numeric|min:0',
            'other_deductions' => 'nullable|numeric|min:0',
        ]);

        $autoAllowances = $item->other_allowances - $item->manual_allowances;
        $autoDeductions = $item->other_deductions - $item->manual_deductions;

        if (isset($data['other_allowances'])) {
            $item->manual_allowances = max(0, round($data['other_allowances'] - $autoAllowances, 2));
            $item->other_allowances  = round($autoAllowances + $item->manual_allowances, 2);
        }
        if (isset($data['other_deductions'])) {
            $item->manual_deductions = max(0, round($data['other_deductions'] - $autoDeductions, 2));
            $item->other_deductions  = round($autoDeductions + $item->manual_deductions, 2);
        }

        $item->gross_pay = round($item->basic_salary + $item->other_allowances, 2);
        $statutoryTotal  = round($item->paye + $item->shif + $item->nssf + $item->housing_levy, 2);
        $item->net_pay   = max(0, round($item->gross_pay - $statutoryTotal - $item->other_deductions, 2));
        $item->save();

        $period->update(['total_net' => $period->items()->sum('net_pay')]);

        return ApiResponse::updated($item->load('employee'), 'Payroll item updated');
    }

    public function bankFile(int $id): Response
    {
        $period = PayrollPeriod::with('items.employee')->findOrFail($id);
        $rows   = $this->service->bankFileRows($period);

        $csv = "AccountNumber,BankName,Name,Amount,Remarks\n";
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', $v) . '"', $r)) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"payroll_bank_{$period->ref_no}.csv\"",
        ]);
    }

    // ── Pay components (allowances / deductions catalogue) ─────────────────────

    public function componentsIndex(): JsonResponse
    {
        return ApiResponse::success(
            PayrollPayComponent::with('glAccount:id,code,name')->orderBy('sort_order')->get(),
            'Pay components retrieved'
        );
    }

    public function componentsStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'category'         => 'required|in:allowance,deduction',
            'computation_type' => 'required|in:fixed,percentage_of_basic',
            'default_amount'   => 'nullable|numeric|min:0',
            'percentage'       => 'nullable|numeric|min:0|max:100',
            'is_taxable'       => 'boolean',
            'gl_account_id'    => 'nullable|integer|exists:gl_accounts,id',
            'sort_order'       => 'nullable|integer',
        ]);
        $data['is_statutory'] = false;
        $data['active'] = true;

        return ApiResponse::created(PayrollPayComponent::create($data), 'Pay component created');
    }

    public function componentsUpdate(Request $request, int $id): JsonResponse
    {
        $component = PayrollPayComponent::findOrFail($id);
        if ($component->is_statutory) {
            return ApiResponse::error('Statutory components cannot be edited.', 422);
        }

        $data = $request->validate([
            'name'             => 'sometimes|string|max:100',
            'computation_type' => 'sometimes|in:fixed,percentage_of_basic',
            'default_amount'   => 'nullable|numeric|min:0',
            'percentage'       => 'nullable|numeric|min:0|max:100',
            'is_taxable'       => 'boolean',
            'gl_account_id'    => 'nullable|integer|exists:gl_accounts,id',
            'active'           => 'boolean',
            'sort_order'       => 'nullable|integer',
        ]);

        $component->update($data);
        return ApiResponse::updated($component, 'Pay component updated');
    }

    public function componentsDestroy(int $id): JsonResponse
    {
        $component = PayrollPayComponent::findOrFail($id);
        if ($component->is_statutory) {
            return ApiResponse::error('Statutory components cannot be deleted.', 422);
        }
        $component->delete();
        return ApiResponse::deleted('Pay component deleted');
    }

    // ── Employee recurring/one-time pay items ───────────────────────────────────
    // Per-employee earnings/deductions (e.g. a loan for one employee) layered on
    // top of the company-wide catalogue above. Picked up automatically by
    // PayrollGenerationService while active/in range/installments remain.

    public function employeeComponentsIndex(Request $request): JsonResponse
    {
        $query = PayrollEmployeeComponent::with(['employee:id,full_name', 'component:id,name,category'])
            ->orderByDesc('id');

        if ($request->filled('employee_id')) $query->where('employee_id', $request->employee_id);
        if ($request->filled('active')) $query->where('active', (bool) $request->boolean('active'));

        return ApiResponse::success($query->get(), 'Employee pay items retrieved');
    }

    public function employeeComponentsStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'         => 'required|integer|exists:employees,id',
            'component_id'        => 'required|integer|exists:payroll_pay_components,id',
            'amount'              => 'required|numeric|min:0.01',
            'is_recurring'        => 'boolean',
            'start_date'          => 'required|date',
            'end_date'            => 'nullable|date|after_or_equal:start_date',
            'total_installments'  => 'nullable|integer|min:1',
            'notes'               => 'nullable|string|max:255',
        ]);

        $component = PayrollPayComponent::findOrFail($data['component_id']);
        if ($component->is_statutory) {
            return ApiResponse::error('Statutory components are computed automatically and cannot be assigned manually.', 422);
        }
        if ($component->name === 'External Provider Purchases') {
            return ApiResponse::error('This component is managed automatically from ESP settlements and cannot be assigned manually.', 422);
        }

        // A one-time item is just a recurring item capped at a single installment.
        if (! ($data['is_recurring'] ?? true)) {
            $data['total_installments'] = 1;
        }
        $data['installments_used'] = 0;
        $data['active']            = true;
        $data['created_by']        = auth()->id();

        $item = PayrollEmployeeComponent::create($data);

        return ApiResponse::created($item->load(['employee:id,full_name', 'component:id,name,category']), 'Employee pay item created');
    }

    public function employeeComponentsUpdate(Request $request, int $id): JsonResponse
    {
        $item = PayrollEmployeeComponent::findOrFail($id);

        $data = $request->validate([
            'amount'              => 'sometimes|numeric|min:0.01',
            'is_recurring'        => 'boolean',
            'start_date'          => 'sometimes|date',
            'end_date'            => 'nullable|date|after_or_equal:start_date',
            'total_installments'  => 'nullable|integer|min:1',
            'active'              => 'boolean',
            'notes'               => 'nullable|string|max:255',
        ]);

        if (isset($data['is_recurring']) && ! $data['is_recurring']) {
            $data['total_installments'] = 1;
        }

        $item->update($data);

        return ApiResponse::updated($item->load(['employee:id,full_name', 'component:id,name,category']), 'Employee pay item updated');
    }

    public function employeeComponentsDestroy(int $id): JsonResponse
    {
        PayrollEmployeeComponent::findOrFail($id)->delete();
        return ApiResponse::deleted('Employee pay item deleted');
    }

    // ── Statutory & summary reports ─────────────────────────────────────────────

    public function payeComputation(int $id): JsonResponse
    {
        $period = PayrollPeriod::with('items.employee')->findOrFail($id);
        $rows = $period->items->map(fn ($i) => [
            'employee'    => $i->employee->full_name,
            'kra_pin'     => $i->employee->kra_pin,
            'gross_pay'   => (float) $i->gross_pay,
            'nssf'        => (float) $i->nssf,
            'housing_levy'=> (float) $i->housing_levy,
            'taxable_pay' => (float) $i->taxable_pay,
            'paye'        => (float) $i->paye,
        ]);

        return ApiResponse::success([
            'period' => $period->only(['id', 'ref_no', 'period_start', 'period_end']),
            'rows'   => $rows,
            'total_paye' => (float) $period->total_paye,
        ], 'PAYE computation retrieved');
    }

    public function nhifReturns(int $id): JsonResponse
    {
        $period = PayrollPeriod::with('items.employee')->findOrFail($id);
        $rows = $period->items->map(fn ($i) => [
            'employee'  => $i->employee->full_name,
            'shif_no'   => $i->employee->shif_no,
            'gross_pay' => (float) $i->gross_pay,
            'shif'      => (float) $i->shif,
        ]);

        return ApiResponse::success([
            'period' => $period->only(['id', 'ref_no', 'period_start', 'period_end']),
            'rows'   => $rows,
            'total_shif' => (float) $period->total_shif,
        ], 'SHIF (NHIF) returns retrieved');
    }

    public function nssfReturns(int $id): JsonResponse
    {
        $period = PayrollPeriod::with('items.employee')->findOrFail($id);
        $rows = $period->items->map(fn ($i) => [
            'employee'  => $i->employee->full_name,
            'nssf_no'   => $i->employee->nssf_no,
            'gross_pay' => (float) $i->gross_pay,
            'nssf'      => (float) $i->nssf,
        ]);

        return ApiResponse::success([
            'period' => $period->only(['id', 'ref_no', 'period_start', 'period_end']),
            'rows'   => $rows,
            'total_nssf' => (float) $period->total_nssf,
        ], 'NSSF returns retrieved');
    }

    /** Annual per-employee statutory summary (KRA P9-style). */
    public function p9Report(Request $request): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);

        $items = PayrollItem::with(['employee', 'period'])
            ->whereHas('period', fn ($q) => $q->whereYear('period_start', $year)->where('status', 'posted'))
            ->get()
            ->groupBy('employee_id');

        $rows = $items->map(function ($employeeItems) {
            $employee = $employeeItems->first()->employee;
            return [
                'employee'      => $employee->full_name,
                'kra_pin'       => $employee->kra_pin,
                'months'        => $employeeItems->count(),
                'gross_pay'     => round($employeeItems->sum('gross_pay'), 2),
                'nssf'          => round($employeeItems->sum('nssf'), 2),
                'housing_levy'  => round($employeeItems->sum('housing_levy'), 2),
                'taxable_pay'   => round($employeeItems->sum('taxable_pay'), 2),
                'paye'          => round($employeeItems->sum('paye'), 2),
            ];
        })->values();

        return ApiResponse::success(['year' => $year, 'rows' => $rows], 'P9 report retrieved');
    }

    public function summaryReport(Request $request): JsonResponse
    {
        $query = PayrollPeriod::whereIn('status', ['approved', 'posted'])->orderByDesc('period_start');

        if ($request->filled('date_from')) $query->where('period_start', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->where('period_end', '<=', $request->date_to);

        return ApiResponse::success($query->get(), 'Payroll summary report retrieved');
    }

    /** Per-employee payslip breakdown for a period — one printable payslip each. */
    public function payslipReport(int $id): JsonResponse
    {
        $period = PayrollPeriod::with('items.employee')->findOrFail($id);

        $rows = $period->items->map(fn ($i) => [
            'employee'         => $i->employee->full_name,
            'employee_no'      => $i->employee->id,
            'kra_pin'          => $i->employee->kra_pin,
            'bank_name'        => $i->employee->bank_name,
            'bank_account'     => $i->employee->bank_account,
            'basic_salary'     => (float) $i->basic_salary,
            'other_allowances' => (float) $i->other_allowances,
            'gross_pay'        => (float) $i->gross_pay,
            'paye'             => (float) $i->paye,
            'shif'             => (float) $i->shif,
            'nssf'             => (float) $i->nssf,
            'housing_levy'     => (float) $i->housing_levy,
            'other_deductions' => (float) $i->other_deductions,
            'net_pay'          => (float) $i->net_pay,
        ]);

        return ApiResponse::success([
            'period' => $period->only(['id', 'ref_no', 'period_start', 'period_end']),
            'rows'   => $rows,
        ], 'Payslip report retrieved');
    }

    /** Deduction breakdown per employee per component, for a period. */
    public function staffDeductionStatement(int $id): JsonResponse
    {
        $period = PayrollPeriod::findOrFail($id);

        $postings = PayrollPosting::with(['employee', 'component'])
            ->where('payroll_period_id', $id)
            ->whereHas('component', fn ($q) => $q->where('category', 'deduction'))
            ->where('amount', '>', 0)
            ->get()
            ->groupBy(fn ($p) => $p->employee_id);

        $rows = $postings->map(function ($items) {
            $employee = $items->first()->employee;
            return [
                'employee'    => $employee->full_name,
                'components'  => $items->map(fn ($p) => [
                    'name'   => $p->component->name,
                    'amount' => (float) $p->amount,
                ])->values(),
                'total' => round($items->sum('amount'), 2),
            ];
        })->values();

        return ApiResponse::success([
            'period' => $period->only(['id', 'ref_no', 'period_start', 'period_end']),
            'rows'   => $rows,
            'grand_total' => round($rows->sum('total'), 2),
        ], 'Staff deduction statement retrieved');
    }

    /** On-screen preview of the bank payment schedule (same rows as bank-file CSV). */
    public function bankSchedule(int $id): JsonResponse
    {
        $period = PayrollPeriod::with('items.employee')->findOrFail($id);
        $rows   = $this->service->bankFileRows($period);

        $mapped = collect($rows)->map(fn ($r) => [
            'bank_account' => $r[0], 'bank_name' => $r[1], 'employee' => $r[2],
            'amount'       => (float) str_replace(',', '', $r[3]), 'remarks' => $r[4],
        ]);

        return ApiResponse::success([
            'period' => $period->only(['id', 'ref_no', 'period_start', 'period_end']),
            'rows'   => $mapped,
            'total'  => round($mapped->sum('amount'), 2),
        ], 'Bank schedule retrieved');
    }
}
