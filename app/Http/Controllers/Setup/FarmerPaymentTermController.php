<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FarmerPaymentTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmerPaymentTermController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FarmerPaymentTerm::orderBy('description');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Farmer payment terms retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description'    => 'required|string|max:100',
            'type'           => 'required|in:prepayment,after_days,cash,end_of_month',
            'due_after_days' => 'nullable|integer|min:0',
            'shift'          => 'nullable|string|max:20',
        ]);

        $validated['shift'] = $validated['shift'] ?? 'Both';
        $term = FarmerPaymentTerm::create($validated);

        return ApiResponse::created($term, 'Farmer payment term created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $term = FarmerPaymentTerm::findOrFail($id);

        $validated = $request->validate([
            'description'    => 'sometimes|string|max:100',
            'type'           => 'sometimes|in:prepayment,after_days,cash,end_of_month',
            'due_after_days' => 'nullable|integer|min:0',
            'shift'          => 'nullable|string|max:20',
            'inactive'       => 'sometimes|boolean',
        ]);

        $term->fill($validated)->save();

        return ApiResponse::updated($term->fresh(), 'Farmer payment term updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $term = FarmerPaymentTerm::findOrFail($id);
        $term->update(['inactive' => true]);

        return ApiResponse::deleted('Farmer payment term deactivated');
    }
}
