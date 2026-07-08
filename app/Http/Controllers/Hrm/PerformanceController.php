<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\PerformanceGoal;
use App\Models\PerformanceReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PerformanceController extends Controller
{
    // ── Goals ────────────────────────────────────────────────────────────────

    public function goals(int $employeeId): JsonResponse
    {
        $goals = PerformanceGoal::where('employee_id', $employeeId)->orderByDesc('created_at')->get();
        return ApiResponse::success($goals, 'Goals retrieved');
    }

    public function storeGoal(Request $request, int $employeeId): JsonResponse
    {
        if (! Employee::where('id', $employeeId)->exists()) return ApiResponse::notFound('Employee not found');

        $data = $request->validate([
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string',
            'due_date'    => 'nullable|date',
        ]);
        $data['employee_id'] = $employeeId;

        $goal = PerformanceGoal::create($data);

        return ApiResponse::created($goal, 'Goal created');
    }

    public function updateGoal(Request $request, int $id): JsonResponse
    {
        $goal = PerformanceGoal::find($id);
        if (! $goal) return ApiResponse::notFound('Goal not found');

        $data = $request->validate([
            'title'       => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'due_date'    => 'nullable|date',
            'progress'    => 'sometimes|integer|min:0|max:100',
            'status'      => 'sometimes|in:active,completed,cancelled',
        ]);

        if (($data['progress'] ?? null) === 100 && empty($data['status'])) {
            $data['status'] = 'completed';
        }

        $goal->update($data);

        return ApiResponse::updated($goal->fresh(), 'Goal updated');
    }

    public function destroyGoal(int $id): JsonResponse
    {
        $goal = PerformanceGoal::find($id);
        if (! $goal) return ApiResponse::notFound('Goal not found');
        $goal->delete();
        return ApiResponse::deleted('Goal deleted');
    }

    // ── Reviews / Appraisals ───────────────────────────────────────────────

    public function reviews(int $employeeId): JsonResponse
    {
        $reviews = PerformanceReview::with('reviewer:id,full_name')
            ->where('employee_id', $employeeId)
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success($reviews, 'Reviews retrieved');
    }

    public function storeReview(Request $request, int $employeeId): JsonResponse
    {
        if (! Employee::where('id', $employeeId)->exists()) return ApiResponse::notFound('Employee not found');

        $data = $request->validate([
            'period'   => 'required|string|max:40',
            'rating'   => 'nullable|integer|min:1|max:5',
            'comments' => 'nullable|string',
        ]);
        $data['employee_id']  = $employeeId;
        $data['reviewer_id']  = $this->actingEmployeeId();
        $data['status']       = 'draft';

        $review = PerformanceReview::create($data);

        return ApiResponse::created($review->load('reviewer:id,full_name'), 'Review drafted');
    }

    public function updateReview(Request $request, int $id): JsonResponse
    {
        $review = PerformanceReview::find($id);
        if (! $review) return ApiResponse::notFound('Review not found');
        if ($review->status !== 'draft') {
            return ApiResponse::error('Only draft reviews can be edited', 422);
        }

        $data = $request->validate([
            'period'   => 'sometimes|string|max:40',
            'rating'   => 'nullable|integer|min:1|max:5',
            'comments' => 'nullable|string',
        ]);

        $review->update($data);

        return ApiResponse::updated($review->fresh(), 'Review updated');
    }

    public function submitReview(int $id): JsonResponse
    {
        $review = PerformanceReview::find($id);
        if (! $review) return ApiResponse::notFound('Review not found');
        if ($review->status !== 'draft') {
            return ApiResponse::error('Only draft reviews can be submitted', 422);
        }

        $review->update(['status' => 'submitted', 'submitted_at' => now()]);

        return ApiResponse::updated($review->fresh(), 'Review submitted');
    }

    /**
     * The reviewed employee acknowledges having seen the appraisal.
     */
    public function acknowledgeReview(int $id): JsonResponse
    {
        $review = PerformanceReview::find($id);
        if (! $review) return ApiResponse::notFound('Review not found');
        if ($review->status !== 'submitted') {
            return ApiResponse::error('Only submitted reviews can be acknowledged', 422);
        }

        $review->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);

        return ApiResponse::updated($review->fresh(), 'Review acknowledged');
    }

    private function actingEmployeeId(): ?int
    {
        return Employee::where('user_id', Auth::user()?->user_id)->value('id');
    }
}
