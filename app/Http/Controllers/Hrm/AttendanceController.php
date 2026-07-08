<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    // Expected start of day + grace period before a check-in counts as late.
    private const EXPECTED_START = '08:00:00';
    private const GRACE_MINUTES  = 15;

    public function checkIn(): JsonResponse
    {
        $employee = $this->actingEmployee();
        if (! $employee) {
            return ApiResponse::error('No employee record is linked to your login', 422);
        }

        $today  = now()->toDateString();
        $record = AttendanceRecord::firstOrNew(['employee_id' => $employee->id, 'date' => $today]);

        if ($record->exists && $record->check_in) {
            return ApiResponse::error('Already checked in today at ' . $record->check_in->format('H:i'), 422);
        }

        $now      = now();
        $deadline = Carbon::parse($today . ' ' . self::EXPECTED_START)->addMinutes(self::GRACE_MINUTES);

        $record->fill([
            'check_in' => $now,
            'status'   => $now->gt($deadline) ? 'late' : 'present',
        ])->save();

        return ApiResponse::success($record->fresh(), 'Checked in at ' . $now->format('H:i'));
    }

    public function checkOut(): JsonResponse
    {
        $employee = $this->actingEmployee();
        if (! $employee) {
            return ApiResponse::error('No employee record is linked to your login', 422);
        }

        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->where('date', now()->toDateString())
            ->first();

        if (! $record || ! $record->check_in) {
            return ApiResponse::error('You have not checked in today', 422);
        }
        if ($record->check_out) {
            return ApiResponse::error('Already checked out today at ' . $record->check_out->format('H:i'), 422);
        }

        $record->update(['check_out' => now()]);

        return ApiResponse::success($record->fresh(), 'Checked out at ' . now()->format('H:i'));
    }

    /**
     * The current logged-in user's own attendance record for a date
     * (defaults to today) — drives the check-in/check-out button state.
     */
    public function me(Request $request): JsonResponse
    {
        $employee = $this->actingEmployee();
        if (! $employee) {
            return ApiResponse::success(['linked' => false, 'record' => null], 'No employee record linked to this login');
        }

        $date   = $request->get('date', now()->toDateString());
        $record = AttendanceRecord::where('employee_id', $employee->id)->where('date', $date)->first();

        return ApiResponse::success(['linked' => true, 'record' => $record], 'Attendance retrieved');
    }

    /**
     * Whole-company attendance for one day (default today) — every active
     * employee, with a virtual "absent"/"weekend" row when no record exists
     * rather than requiring one to be pre-created for every employee.
     */
    public function forDate(Request $request): JsonResponse
    {
        $date    = $request->get('date', now()->toDateString());
        $isWeekend = Carbon::parse($date)->isWeekend();

        $employees = Employee::where('status', '!=', 'terminated')
            ->orderBy('full_name')
            ->get(['id', 'emp_no', 'full_name', 'department_id']);

        $records = AttendanceRecord::where('date', $date)->get()->keyBy('employee_id');

        $rows = $employees->map(function (Employee $e) use ($records, $isWeekend) {
            $r = $records->get($e->id);
            return [
                'employee_id' => $e->id,
                'emp_no'      => $e->emp_no,
                'full_name'   => $e->full_name,
                'check_in'    => $r?->check_in?->format('H:i'),
                'check_out'   => $r?->check_out?->format('H:i'),
                'status'      => $r?->status ?? ($isWeekend ? 'weekend' : 'absent'),
            ];
        });

        return ApiResponse::success([
            'date' => $date,
            'rows' => $rows,
            'summary' => [
                'present' => $rows->whereIn('status', ['present'])->count(),
                'late'    => $rows->where('status', 'late')->count(),
                'absent'  => $rows->where('status', 'absent')->count(),
            ],
        ], 'Attendance retrieved');
    }

    public function history(int $employeeId, Request $request): JsonResponse
    {
        $query = AttendanceRecord::where('employee_id', $employeeId)->orderByDesc('date');

        if ($request->filled('from')) $query->where('date', '>=', $request->from);
        if ($request->filled('to'))   $query->where('date', '<=', $request->to);

        return ApiResponse::success($query->limit(90)->get(), 'Attendance history retrieved');
    }

    /**
     * HR override — backfill or correct a record directly rather than
     * through self check-in/out (e.g. forgotten clock-in, field staff).
     */
    public function manual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'date'        => 'required|date',
            'check_in'    => 'nullable|date_format:H:i',
            'check_out'   => 'nullable|date_format:H:i',
            'status'      => 'required|in:present,late,half_day,absent',
            'notes'       => 'nullable|string',
        ]);

        $record = AttendanceRecord::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'date' => $data['date']],
            [
                'check_in'  => $data['check_in']  ? $data['date'] . ' ' . $data['check_in']  : null,
                'check_out' => $data['check_out'] ? $data['date'] . ' ' . $data['check_out'] : null,
                'status'    => $data['status'],
                'notes'     => $data['notes'] ?? null,
            ]
        );

        return ApiResponse::success($record->fresh(), 'Attendance record saved');
    }

    private function actingEmployee(): ?Employee
    {
        return Employee::where('user_id', Auth::user()?->user_id)->first();
    }
}
