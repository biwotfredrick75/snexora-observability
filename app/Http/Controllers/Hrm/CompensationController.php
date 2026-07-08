<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\SalaryRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CompensationController extends Controller
{
    /**
     * Current package + salary history + actual pay run history (the latter
     * read straight from the Payroll module's own tables — Compensation
     * doesn't recompute or duplicate pay, it just surfaces it per employee).
     */
    public function summary(int $employeeId): JsonResponse
    {
        $employee = Employee::find($employeeId);
        if (! $employee) return ApiResponse::notFound('Employee not found');

        $revisions = SalaryRevision::with('creator:id,full_name')
            ->where('employee_id', $employeeId)
            ->orderByDesc('effective_date')
            ->get();

        $payHistory = DB::table('payroll_items as pi')
            ->join('payroll_periods as pp', 'pp.id', '=', 'pi.payroll_period_id')
            ->where('pi.employee_id', $employeeId)
            ->orderByDesc('pp.period_start')
            ->select('pp.ref_no', 'pp.period_start', 'pp.period_end', 'pp.status',
                'pi.gross_pay', 'pi.paye', 'pi.shif', 'pi.nssf', 'pi.housing_levy', 'pi.net_pay', 'pi.payment_status')
            ->limit(24)
            ->get();

        return ApiResponse::success([
            'employee' => [
                'id'             => $employee->id,
                'emp_no'         => $employee->emp_no,
                'full_name'      => $employee->full_name,
                'basic_salary'   => $employee->basic_salary,
                'payment_method' => $employee->payment_method,
                'bank_name'      => $employee->bank_name,
                'bank_branch'    => $employee->bank_branch,
                'bank_account'   => $employee->bank_account,
            ],
            'revisions'    => $revisions,
            'pay_history'  => $payHistory,
        ], 'Compensation summary retrieved');
    }

    /**
     * Record a salary change and apply it to the employee's basic_salary
     * in one step, so the two can never drift apart.
     */
    public function reviseSalary(Request $request, int $employeeId): JsonResponse
    {
        $employee = Employee::find($employeeId);
        if (! $employee) return ApiResponse::notFound('Employee not found');

        $data = $request->validate([
            'new_salary'     => 'required|numeric|min:0',
            'effective_date' => 'required|date',
            'reason'         => 'nullable|string',
        ]);

        $revision = DB::transaction(function () use ($employee, $data) {
            $revision = SalaryRevision::create([
                'employee_id'      => $employee->id,
                'previous_salary'  => $employee->basic_salary,
                'new_salary'       => $data['new_salary'],
                'effective_date'   => $data['effective_date'],
                'reason'           => $data['reason'] ?? null,
                'created_by'       => Employee::where('user_id', Auth::user()?->user_id)->value('id'),
            ]);

            $employee->update(['basic_salary' => $data['new_salary']]);

            return $revision;
        });

        return ApiResponse::created($revision->load('creator:id,full_name'), 'Salary revision recorded');
    }
}
