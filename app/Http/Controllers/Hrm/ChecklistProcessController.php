<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\HrChecklistItem;
use App\Models\HrChecklistProcess;
use App\Models\HrChecklistTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChecklistProcessController extends Controller
{
    /**
     * All processes of a type (onboarding|offboarding), with task
     * completion counts — the HR-facing dashboard list.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:onboarding,offboarding']);

        $processes = HrChecklistProcess::with(['employee:id,emp_no,full_name', 'tasks'])
            ->where('type', $data['type'])
            ->orderByDesc('started_at')
            ->get()
            ->map(function (HrChecklistProcess $p) {
                $total = $p->tasks->count();
                $done  = $p->tasks->where('is_done', true)->count();
                return [
                    'id'          => $p->id,
                    'employee'    => $p->employee,
                    'type'        => $p->type,
                    'status'      => $p->status,
                    'started_at'  => $p->started_at,
                    'completed_at'=> $p->completed_at,
                    'tasks_total' => $total,
                    'tasks_done'  => $done,
                    'percent'     => $total > 0 ? (int) round($done / $total * 100) : 0,
                ];
            });

        return ApiResponse::success($processes, 'Checklist processes retrieved');
    }

    public function start(Request $request, int $employeeId): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:onboarding,offboarding']);

        $employee = Employee::find($employeeId);
        if (! $employee) return ApiResponse::notFound('Employee not found');

        if ($data['type'] === 'offboarding' && $employee->status === 'terminated') {
            return ApiResponse::error('This employee has already been offboarded', 422);
        }

        $existing = HrChecklistProcess::where('employee_id', $employeeId)
            ->where('type', $data['type'])
            ->where('status', 'in_progress')
            ->first();
        if ($existing) {
            return ApiResponse::success($existing->load('tasks'), 'A process is already in progress for this employee');
        }

        $process = DB::transaction(function () use ($employeeId, $data) {
            $process = HrChecklistProcess::create([
                'employee_id' => $employeeId,
                'type'        => $data['type'],
                'status'      => 'in_progress',
                'started_at'  => now(),
            ]);

            $items = HrChecklistItem::where('type', $data['type'])->where('inactive', false)->orderBy('sort_order')->get();
            foreach ($items as $item) {
                HrChecklistTask::create([
                    'process_id'  => $process->id,
                    'title'       => $item->title,
                    'description' => $item->description,
                    'category'    => $item->category,
                    'sort_order'  => $item->sort_order,
                ]);
            }

            return $process;
        });

        return ApiResponse::created($process->load('tasks'), ucfirst($data['type']) . ' process started');
    }

    /**
     * The active (or most recent) process of a type for one employee,
     * with its task list — the employee-facing detail view.
     */
    public function show(Request $request, int $employeeId): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:onboarding,offboarding']);

        $process = HrChecklistProcess::with('tasks', 'employee:id,emp_no,full_name')
            ->where('employee_id', $employeeId)
            ->where('type', $data['type'])
            ->orderByDesc('started_at')
            ->first();

        return ApiResponse::success($process, $process ? 'Process retrieved' : 'No process found');
    }

    public function toggleTask(int $taskId): JsonResponse
    {
        $task = HrChecklistTask::find($taskId);
        if (! $task) return ApiResponse::notFound('Task not found');

        $task->update($task->is_done
            ? ['is_done' => false, 'done_at' => null, 'done_by' => null]
            : ['is_done' => true, 'done_at' => now(), 'done_by' => $this->actingEmployeeId()]
        );

        return ApiResponse::updated($task->fresh(), $task->is_done ? 'Task marked done' : 'Task marked not done');
    }

    public function complete(Request $request, int $processId): JsonResponse
    {
        $process = HrChecklistProcess::with('tasks', 'employee')->find($processId);
        if (! $process) return ApiResponse::notFound('Process not found');
        if ($process->status === 'completed') {
            return ApiResponse::error('Process is already completed', 422);
        }

        $pending = $process->tasks->where('is_done', false);
        $force   = $request->boolean('force');

        if ($pending->isNotEmpty() && ! $force) {
            return ApiResponse::error(
                'Cannot complete — ' . $pending->count() . ' task(s) still pending: ' . $pending->pluck('title')->implode(', '),
                422
            );
        }

        $process->update(['status' => 'completed', 'completed_at' => now()]);

        // Completing offboarding means the employee has actually left.
        if ($process->type === 'offboarding') {
            $process->employee?->update(['status' => 'terminated', 'end_date' => now()->toDateString()]);
        }

        return ApiResponse::updated($process->fresh(['tasks', 'employee:id,emp_no,full_name']), ucfirst($process->type) . ' process completed');
    }

    private function actingEmployeeId(): ?int
    {
        return Employee::where('user_id', Auth::user()?->user_id)->value('id');
    }
}
