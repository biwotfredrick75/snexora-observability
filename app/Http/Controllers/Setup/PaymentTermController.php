<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PaymentTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentTermController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PaymentTerm::orderBy('description');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Payment terms retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'description'    => 'required|string|max:100',
            'type'           => 'required|in:prepayment,after_days,cash,end_of_month',
            'due_after_days' => 'nullable|integer|min:0',
        ]);

        $term = PaymentTerm::create($validated);

        return ApiResponse::created($term, 'Payment term created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $term = PaymentTerm::findOrFail($id);

        $validated = $request->validate([
            'description'    => 'sometimes|string|max:100',
            'type'           => 'sometimes|in:prepayment,after_days,cash,end_of_month',
            'due_after_days' => 'nullable|integer|min:0',
            'inactive'       => 'sometimes|boolean',
        ]);

        $term->fill($validated)->save();

        return ApiResponse::updated($term->fresh(), 'Payment term updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $term = PaymentTerm::findOrFail($id);
        $term->update(['inactive' => true]);

        return ApiResponse::deleted('Payment term deactivated');
    }
}
