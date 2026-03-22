<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $debtorNo = $request->get('debtor_no');
        $query    = CustomerContact::query();

        if ($debtorNo) {
            $query->where('debtor_no', $debtorNo);
        }

        return ApiResponse::success($query->orderBy('full_name')->get(), 'Contacts retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'debtor_no'       => 'required|string|max:10|exists:customers,debtor_no',
            'assignment'      => 'nullable|string|max:100',
            'reference'       => 'nullable|string|max:200',
            'full_name'       => 'required|string|max:100',
            'phone'           => 'nullable|string|max:30',
            'secondary_phone' => 'nullable|string|max:30',
            'fax'             => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:100',
        ]);

        $contact = CustomerContact::create($data);

        return ApiResponse::created($contact, 'Contact created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contact = CustomerContact::find($id);

        if (! $contact) {
            return ApiResponse::notFound('Contact not found');
        }

        $data = $request->validate([
            'assignment'      => 'nullable|string|max:100',
            'reference'       => 'nullable|string|max:200',
            'full_name'       => 'sometimes|required|string|max:100',
            'phone'           => 'nullable|string|max:30',
            'secondary_phone' => 'nullable|string|max:30',
            'fax'             => 'nullable|string|max:30',
            'email'           => 'nullable|email|max:100',
        ]);

        $contact->update($data);

        return ApiResponse::updated($contact->fresh(), 'Contact updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $contact = CustomerContact::find($id);

        if (! $contact) {
            return ApiResponse::notFound('Contact not found');
        }

        $contact->delete();

        return ApiResponse::deleted('Contact deleted');
    }
}
