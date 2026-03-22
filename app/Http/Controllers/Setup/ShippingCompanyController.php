<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ShippingCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingCompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ShippingCompany::orderBy('name');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Shipping companies retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'contact_person'   => 'nullable|string|max:100',
            'phone'            => 'nullable|string|max:30',
            'secondary_phone'  => 'nullable|string|max:30',
            'address'          => 'nullable|string|max:255',
        ]);

        $company = ShippingCompany::create($validated);

        return ApiResponse::created($company, 'Shipping company created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = ShippingCompany::findOrFail($id);

        $validated = $request->validate([
            'name'             => 'sometimes|string|max:100',
            'contact_person'   => 'nullable|string|max:100',
            'phone'            => 'nullable|string|max:30',
            'secondary_phone'  => 'nullable|string|max:30',
            'address'          => 'nullable|string|max:255',
            'inactive'         => 'sometimes|boolean',
        ]);

        $company->fill($validated)->save();

        return ApiResponse::updated($company->fresh(), 'Shipping company updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $company = ShippingCompany::findOrFail($id);
        $company->update(['inactive' => true]);

        return ApiResponse::deleted('Shipping company deactivated');
    }
}
