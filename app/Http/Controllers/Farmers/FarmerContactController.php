<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FarmerContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FarmerContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FarmerContact::query();
        if ($request->filled('farmer_id')) {
            $query->where('farmer_id', $request->farmer_id);
        }
        return ApiResponse::success($query->orderBy('full_name')->get(), 'Contacts retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'farmer_id'       => 'required|integer|exists:farmers,id',
            'assignment'      => 'nullable|string|max:100',
            'reference'       => 'nullable|string|max:100',
            'full_name'       => 'required|string|max:200',
            'phone'           => 'nullable|string|max:30',
            'secondary_phone' => 'nullable|string|max:30',
            'fax'             => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
        ]);
        $contact = FarmerContact::create($data);
        return ApiResponse::created($contact, 'Contact created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contact = FarmerContact::findOrFail($id);
        $data = $request->validate([
            'assignment'      => 'nullable|string|max:100',
            'reference'       => 'nullable|string|max:100',
            'full_name'       => 'required|string|max:200',
            'phone'           => 'nullable|string|max:30',
            'secondary_phone' => 'nullable|string|max:30',
            'fax'             => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:150',
        ]);
        $contact->fill($data)->save();
        return ApiResponse::updated($contact->fresh(), 'Contact updated');
    }

    public function destroy(int $id): JsonResponse
    {
        FarmerContact::findOrFail($id)->delete();
        return ApiResponse::deleted('Contact deleted');
    }
}
