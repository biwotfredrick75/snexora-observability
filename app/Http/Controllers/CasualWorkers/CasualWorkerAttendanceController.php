<?php

namespace App\Http\Controllers\CasualWorkers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CasualWorker;
use App\Models\CasualWorkerAttendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CasualWorkerAttendanceController extends Controller
{
    /** Get attendance sheet for a date (or date range). */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date'      => 'nullable|date',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
            'worker_id' => 'nullable|integer|exists:casual_workers,id',
        ]);

        $query = CasualWorkerAttendance::with('worker.trade');

        if ($request->filled('date')) {
            $query->where('work_date', $request->date);
        } elseif ($request->filled('date_from')) {
            $query->whereBetween('work_date', [$request->date_from, $request->date_to ?? $request->date_from]);
        }

        if ($request->filled('worker_id')) {
            $query->where('worker_id', $request->worker_id);
        }

        return ApiResponse::success($query->orderBy('work_date')->orderBy('worker_id')->get(), 'Attendance retrieved');
    }

    /**
     * Save an entire day's attendance in one call.
     * Payload: { date, shift, records: [{worker_id, status, hours_worked, check_in, check_out, notes}] }
     */
    public function bulkSave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'                    => 'required|date',
            'shift'                   => 'required|in:full_day,morning,afternoon,night',
            'records'                 => 'required|array|min:1',
            'records.*.worker_id'     => 'required|integer|exists:casual_workers,id',
            'records.*.status'        => 'required|in:present,absent,late,half_day',
            'records.*.hours_worked'  => 'nullable|numeric|min:0|max:24',
            'records.*.check_in'      => 'nullable|string|max:10',
            'records.*.check_out'     => 'nullable|string|max:10',
            'records.*.notes'         => 'nullable|string|max:255',
        ]);

        $userId = auth()->id();

        DB::transaction(function () use ($data, $userId) {
            foreach ($data['records'] as $row) {
                CasualWorkerAttendance::updateOrCreate(
                    ['worker_id' => $row['worker_id'], 'work_date' => $data['date'], 'shift' => $data['shift']],
                    [
                        'status'       => $row['status'],
                        'hours_worked' => $row['hours_worked'] ?? ($row['status'] === 'half_day' ? 4 : 8),
                        'check_in'     => $row['check_in']  ?? null,
                        'check_out'    => $row['check_out'] ?? null,
                        'notes'        => $row['notes']     ?? null,
                        'recorded_by'  => $userId,
                    ]
                );
            }
        });

        $saved = CasualWorkerAttendance::with('worker.trade')
            ->where('work_date', $data['date'])
            ->where('shift', $data['shift'])
            ->get();

        return ApiResponse::success($saved, 'Attendance saved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $attendance = CasualWorkerAttendance::findOrFail($id);

        $data = $request->validate([
            'status'       => 'required|in:present,absent,late,half_day',
            'hours_worked' => 'nullable|numeric|min:0|max:24',
            'check_in'     => 'nullable|string|max:10',
            'check_out'    => 'nullable|string|max:10',
            'notes'        => 'nullable|string|max:255',
        ]);

        $attendance->update($data);
        return ApiResponse::updated($attendance->load('worker'), 'Attendance updated');
    }

    /** Distinct dates that have at least one attendance record (for backdating panel). */
    public function dates(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $from = $request->from ?? now()->subDays(60)->toDateString();
        $to   = $request->to   ?? now()->toDateString();

        $dates = CasualWorkerAttendance::query()
            ->whereBetween('work_date', [$from, $to])
            ->select('work_date', DB::raw('COUNT(DISTINCT worker_id) as worker_count'))
            ->groupBy('work_date')
            ->orderByDesc('work_date')
            ->get();

        return ApiResponse::success($dates, 'Attendance dates retrieved');
    }

    /** Summary: days worked per worker in a date range. */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'trade_id'  => 'nullable|integer|exists:casual_worker_trades,id',
        ]);

        $query = CasualWorkerAttendance::query()
            ->whereBetween('work_date', [$request->date_from, $request->date_to])
            ->whereIn('status', ['present', 'late', 'half_day'])
            ->select('worker_id',
                DB::raw('SUM(CASE WHEN status = "half_day" THEN 0.5 ELSE 1 END) as days_worked'),
                DB::raw('SUM(hours_worked) as hours_worked')
            )
            ->groupBy('worker_id')
            ->with('worker.trade');

        if ($request->filled('trade_id')) {
            $query->whereHas('worker', fn($q) => $q->where('trade_id', $request->trade_id));
        }

        return ApiResponse::success($query->get(), 'Summary retrieved');
    }
}
