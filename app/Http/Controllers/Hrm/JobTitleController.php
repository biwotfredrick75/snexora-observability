<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobTitleController extends Controller
{
    public function index(): JsonResponse
    {
        $jobTitles = JobTitle::withCount('employees')->orderBy('name')->get();

        return ApiResponse::success($jobTitles, 'Job titles retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'        => 'required|string|max:30|unique:job_titles,code',
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'inactive'    => 'sometimes|boolean',
        ]);

        $jobTitle = JobTitle::create($data);

        return ApiResponse::created($jobTitle, 'Job title created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $jobTitle = JobTitle::findOrFail($id);

        $data = $request->validate([
            'code'        => "sometimes|string|max:30|unique:job_titles,code,{$id}",
            'name'        => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:255',
            'inactive'    => 'sometimes|boolean',
        ]);

        $jobTitle->fill($data)->save();

        return ApiResponse::updated($jobTitle->fresh(), 'Job title updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $jobTitle = JobTitle::findOrFail($id);
        $jobTitle->update(['inactive' => true]);

        return ApiResponse::deleted('Job title deactivated');
    }
}
