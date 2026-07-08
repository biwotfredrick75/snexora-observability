<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeaveRequest::with(['employee:id,emp_no,first_name,last_name,full_name', 'leaveType', 'approver:id,full_name'])
            ->orderByDesc('created_at');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return ApiResponse::success($query->get(), 'Leave requests retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'   => 'required|integer|exists:employees,id',
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'reason'        => 'nullable|string',
        ]);

        $days = $this->businessDays($data['start_date'], $data['end_date']);
        if ($days <= 0) {
            return ApiResponse::error('The selected range contains no working days', 422);
        }

        $overlap = LeaveRequest::where('employee_id', $data['employee_id'])
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $data['end_date'])
            ->where('end_date', '>=', $data['start_date'])
            ->exists();
        if ($overlap) {
            return ApiResponse::error('This employee already has a pending or approved leave request overlapping these dates', 422);
        }

        $leaveType = LeaveType::find($data['leave_type_id']);
        if ($leaveType->default_days_per_year > 0) {
            $remaining = $this->remainingDays($data['employee_id'], $leaveType);
            if ($days > $remaining) {
                return ApiResponse::error(
                    "Requested {$days} day(s) exceeds remaining {$leaveType->name} balance of {$remaining} day(s) this year",
                    422
                );
            }
        }

        $leaveRequest = LeaveRequest::create([
            ...$data,
            'days'   => $days,
            'status' => 'pending',
        ]);

        return ApiResponse::created($leaveRequest->load(['employee:id,emp_no,first_name,last_name,full_name', 'leaveType']), 'Leave request submitted');
    }

    public function approve(int $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::with('leaveType')->find($id);
        if (! $leaveRequest) return ApiResponse::notFound('Leave request not found');
        if ($leaveRequest->status !== 'pending') {
            return ApiResponse::error('Only pending requests can be approved', 422);
        }

        $leaveRequest->update([
            'status'      => 'approved',
            'approved_by' => $this->actingEmployeeId(),
            'approved_at' => now(),
        ]);

        // Reflect on the employee record if the approved leave covers today.
        if (now()->between($leaveRequest->start_date, $leaveRequest->end_date)) {
            $leaveRequest->employee?->update(['status' => 'on_leave']);
        }

        return ApiResponse::updated($leaveRequest->fresh(['employee', 'leaveType', 'approver']), 'Leave request approved');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::find($id);
        if (! $leaveRequest) return ApiResponse::notFound('Leave request not found');
        if ($leaveRequest->status !== 'pending') {
            return ApiResponse::error('Only pending requests can be rejected', 422);
        }

        $data = $request->validate(['decision_notes' => 'nullable|string']);

        $leaveRequest->update([
            'status'         => 'rejected',
            'approved_by'    => $this->actingEmployeeId(),
            'approved_at'    => now(),
            'decision_notes' => $data['decision_notes'] ?? null,
        ]);

        return ApiResponse::updated($leaveRequest->fresh(['employee', 'leaveType', 'approver']), 'Leave request rejected');
    }

    public function cancel(int $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::find($id);
        if (! $leaveRequest) return ApiResponse::notFound('Leave request not found');
        if (! in_array($leaveRequest->status, ['pending', 'approved'])) {
            return ApiResponse::error('Only pending or approved requests can be cancelled', 422);
        }

        $leaveRequest->update(['status' => 'cancelled']);

        return ApiResponse::updated($leaveRequest->fresh(), 'Leave request cancelled');
    }

    /**
     * Per-employee balance summary across all active leave types for the
     * current calendar year — computed on the fly from approved requests
     * rather than a separately-maintained ledger, so it can never drift.
     */
    public function balance(int $employeeId): JsonResponse
    {
        $employee = Employee::find($employeeId);
        if (! $employee) return ApiResponse::notFound('Employee not found');

        $rows = LeaveType::where('inactive', false)->orderBy('name')->get()->map(function (LeaveType $type) use ($employeeId) {
            $taken = $this->takenDays($employeeId, $type);
            return [
                'leave_type_id'  => $type->id,
                'code'           => $type->code,
                'name'           => $type->name,
                'is_paid'        => $type->is_paid,
                'entitled_days'  => $type->default_days_per_year,
                'taken_days'     => $taken,
                'remaining_days' => $type->default_days_per_year > 0 ? max(0, $type->default_days_per_year - $taken) : null,
            ];
        });

        return ApiResponse::success($rows, 'Leave balance retrieved');
    }

    private function takenDays(int $employeeId, LeaveType $type): float
    {
        return (float) LeaveRequest::where('employee_id', $employeeId)
            ->where('leave_type_id', $type->id)
            ->where('status', 'approved')
            ->whereYear('start_date', now()->year)
            ->sum('days');
    }

    private function remainingDays(int $employeeId, LeaveType $type): float
    {
        return max(0, $type->default_days_per_year - $this->takenDays($employeeId, $type));
    }

    private function businessDays(string $start, string $end): int
    {
        $count = 0;
        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (!$date->isWeekend()) $count++;
        }
        return $count;
    }

    private function actingEmployeeId(): ?int
    {
        return Employee::where('user_id', Auth::user()?->user_id)->value('id');
    }
}
