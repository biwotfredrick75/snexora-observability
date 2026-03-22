<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkCollectionSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkSessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MilkCollectionSession::orderBy('description');
        if (!$request->boolean('show_inactive')) {
            $query->where('active', true);
        }
        return ApiResponse::success($query->get(), 'Sessions retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:150',
            'start_time'  => 'required|string|max:10',
            'end_time'    => 'required|string|max:10',
            'active'      => 'sometimes|boolean',
        ]);
        $session = MilkCollectionSession::create($data);
        return ApiResponse::created($session, 'Session created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $session = MilkCollectionSession::findOrFail($id);
        $data    = $request->validate([
            'description' => 'sometimes|string|max:150',
            'start_time'  => 'sometimes|string|max:10',
            'end_time'    => 'sometimes|string|max:10',
            'active'      => 'sometimes|boolean',
        ]);
        $session->fill($data)->save();
        return ApiResponse::updated($session->fresh(), 'Session updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkCollectionSession::findOrFail($id)->delete();
        return ApiResponse::deleted('Session deleted');
    }
}
