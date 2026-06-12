<?php

namespace App\Modules\Hrm\Services;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveBalance;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\LeaveType;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    private const RELATIONS = [
        'employee:id,emp_no,first_name,middle_name,last_name',
        'leaveType:id,code,name,color',
    ];

    /**
     * Working days between two dates, inclusive, weekends excluded.
     */
    public function workingDays(string $start, string $end): float
    {
        $from = Carbon::parse($start)->startOfDay();
        $to   = Carbon::parse($end)->startOfDay();

        if ($to->lt($from)) {
            return 0.0;
        }

        $days = 0;
        foreach (CarbonPeriod::create($from, $to) as $day) {
            if ($day->isWeekday()) {
                $days++;
            }
        }

        return (float) $days;
    }

    /**
     * Find or create the balance row for an employee / type / year.
     * A new row is seeded with the leave type's annual entitlement.
     */
    public function ensureBalance(int $employeeId, int $leaveTypeId, int $year): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            ['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'year' => $year],
            ['entitled_days' => LeaveType::whereKey($leaveTypeId)->value('days_per_year') ?? 0],
        );
    }

    /**
     * Submit a leave request and reserve the days as pending balance.
     */
    public function apply(array $data): LeaveRequest
    {
        return DB::transaction(function () use ($data) {
            $type = LeaveType::findOrFail($data['leave_type_id']);
            $days = $this->workingDays($data['start_date'], $data['end_date']);

            if ($days <= 0) {
                throw ValidationException::withMessages([
                    'end_date' => 'The selected range contains no working days.',
                ]);
            }

            $year    = (int) Carbon::parse($data['start_date'])->year;
            $balance = $this->ensureBalance((int) $data['employee_id'], $type->id, $year);

            if ($days > $balance->available_days) {
                throw ValidationException::withMessages([
                    'leave_type_id' => "Insufficient balance — {$balance->available_days} day(s) available, {$days} requested.",
                ]);
            }

            $request = LeaveRequest::create([
                'request_no'    => $this->nextRequestNo(),
                'employee_id'   => $data['employee_id'],
                'leave_type_id' => $type->id,
                'start_date'    => $data['start_date'],
                'end_date'      => $data['end_date'],
                'days'          => $days,
                'reason'        => $data['reason'] ?? null,
                'status'        => 'pending',
                'applied_at'    => now(),
            ]);

            $balance->increment('pending_days', $days);

            return $request->load(self::RELATIONS);
        });
    }

    /**
     * Approve or reject a pending request, moving the reserved days.
     */
    public function decide(int $requestId, string $action, ?string $approver, ?string $notes): LeaveRequest
    {
        return DB::transaction(function () use ($requestId, $action, $approver, $notes) {
            $request = LeaveRequest::findOrFail($requestId);

            if ($request->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => 'Only pending requests can be approved or rejected.',
                ]);
            }

            $year    = (int) Carbon::parse($request->start_date)->year;
            $balance = $this->ensureBalance($request->employee_id, $request->leave_type_id, $year);

            if ($action === 'approved') {
                $balance->decrement('pending_days', $request->days);
                $balance->increment('taken_days', $request->days);
            } else { // rejected — release the reservation
                $balance->decrement('pending_days', $request->days);
            }

            $request->update([
                'status'         => $action,
                'approved_by'    => $approver,
                'approved_at'    => now(),
                'approval_notes' => $notes,
            ]);

            return $request->fresh(self::RELATIONS);
        });
    }

    /**
     * Cancel a pending or approved request and return the days to balance.
     */
    public function cancel(int $requestId): LeaveRequest
    {
        return DB::transaction(function () use ($requestId) {
            $request = LeaveRequest::findOrFail($requestId);

            if (! in_array($request->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending or approved requests can be cancelled.',
                ]);
            }

            $year    = (int) Carbon::parse($request->start_date)->year;
            $balance = $this->ensureBalance($request->employee_id, $request->leave_type_id, $year);

            if ($request->status === 'pending') {
                $balance->decrement('pending_days', $request->days);
            } else {
                $balance->decrement('taken_days', $request->days);
            }

            $request->update(['status' => 'cancelled']);

            return $request->fresh(self::RELATIONS);
        });
    }

    /**
     * Seed balance rows for every active employee × active leave type for a year.
     * Returns the number of rows created.
     */
    public function generateBalances(int $year): int
    {
        $employeeIds = Employee::whereIn('status', ['active', 'on_leave'])->pluck('id');
        $types       = LeaveType::where('inactive', false)->get();
        $created     = 0;

        foreach ($employeeIds as $employeeId) {
            foreach ($types as $type) {
                $balance = LeaveBalance::firstOrNew([
                    'employee_id'   => $employeeId,
                    'leave_type_id' => $type->id,
                    'year'          => $year,
                ]);

                if (! $balance->exists) {
                    $balance->entitled_days = $type->days_per_year;
                    $balance->save();
                    $created++;
                }
            }
        }

        return $created;
    }

    public function nextRequestNo(): string
    {
        $last = LeaveRequest::orderByDesc('id')->value('request_no');
        $n    = 1;

        if ($last && preg_match('/(\d+)\s*$/', $last, $m)) {
            $n = (int) $m[1] + 1;
        }

        return 'LV-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
