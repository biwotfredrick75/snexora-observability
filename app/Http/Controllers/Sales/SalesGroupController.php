<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesGroupController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(SalesGroup::orderBy('group_name')->get(), 'Sales groups retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['group_name' => 'required|string|max:100']);
        return ApiResponse::created(SalesGroup::create($data), 'Sales group added');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $group = SalesGroup::findOrFail($id);
        $data  = $request->validate(['group_name' => 'required|string|max:100']);
        $group->update($data);
        return ApiResponse::updated($group, 'Sales group updated');
    }

    public function destroy(int $id): JsonResponse
    {
        SalesGroup::findOrFail($id)->delete();
        return ApiResponse::deleted('Sales group deleted');
    }
}
