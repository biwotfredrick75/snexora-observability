<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Hrm\Http\Requests\JobTitleRequest;
use App\Modules\Hrm\Models\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobTitleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = JobTitle::withCount('employees')->orderBy('name');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($w) use ($term) {
                $w->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        return ApiResponse::success($query->get());
    }

    public function store(JobTitleRequest $request): JsonResponse
    {
        return ApiResponse::created(
            JobTitle::create($request->validated()),
            'Job title created'
        );
    }

    public function update(JobTitleRequest $request, int $id): JsonResponse
    {
        $jobTitle = JobTitle::find($id);

        if (! $jobTitle) {
            return ApiResponse::notFound('Job title not found');
        }

        $jobTitle->update($request->validated());

        return ApiResponse::updated($jobTitle, 'Job title updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $jobTitle = JobTitle::find($id);

        if (! $jobTitle) {
            return ApiResponse::notFound('Job title not found');
        }

        $jobTitle->update(['inactive' => true]);

        return ApiResponse::deleted('Job title deactivated');
    }
}
