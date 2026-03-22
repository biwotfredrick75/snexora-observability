<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FarmerBank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmerBankController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(FarmerBank::orderBy('name')->get(), 'Banks retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
        ]);
        $bank = FarmerBank::create($data);
        return ApiResponse::created($bank, 'Bank created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $bank = FarmerBank::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:150',
        ]);
        $bank->fill($data)->save();
        return ApiResponse::updated($bank->fresh(), 'Bank updated');
    }

    public function destroy(int $id): JsonResponse
    {
        FarmerBank::findOrFail($id)->delete();
        return ApiResponse::deleted('Bank deleted');
    }
}
