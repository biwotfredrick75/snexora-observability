<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerBranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $debtorNo = $request->get('debtor_no');

        if (! $debtorNo) {
            return ApiResponse::error('debtor_no query parameter is required', 422);
        }

        $branches = CustomerBranch::where('debtor_no', $debtorNo)
            ->orderBy('branch_name')
            ->get();

        return ApiResponse::success($branches, 'Branches retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'debtor_no'               => 'required|string|max:10|exists:customers,debtor_no',
            'branch_name'             => 'required|string|max:100',
            'branch_short_name'       => 'nullable|string|max:50',
            'phone'                   => 'nullable|string|max:30',
            'deliver_to'              => 'nullable|string|max:100',
            'address'                 => 'nullable|string',
            'sales_person_id'         => 'nullable|integer',
            'sales_area_id'           => 'nullable|integer',
            'sales_group_id'          => 'nullable|integer',
            'location_id'             => 'nullable|integer',
            'shipping_company_id'     => 'nullable|integer',
            'tax_group_id'            => 'nullable|integer',
            'sales_account'           => 'nullable|string|max:15',
            'sales_discount_account'  => 'nullable|string|max:15',
            'ar_account'              => 'nullable|string|max:15',
            'prompt_discount_account' => 'nullable|string|max:15',
            'bank_account_number'     => 'nullable|string|max:50',
            'contact_person'          => 'nullable|string|max:100',
            'secondary_phone'         => 'nullable|string|max:30',
            'fax'                     => 'nullable|string|max:30',
            'email'                   => 'nullable|string|max:100',
            'document_language'       => 'nullable|string|max:30',
            'mailing_address'         => 'nullable|string',
            'billing_address'         => 'nullable|string',
            'general_notes'           => 'nullable|string',
        ]);

        $branch = CustomerBranch::create($data);

        return ApiResponse::created($branch, 'Branch created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $branch = CustomerBranch::find($id);

        if (! $branch) {
            return ApiResponse::notFound('Branch not found');
        }

        $data = $request->validate([
            'branch_name'             => 'sometimes|required|string|max:100',
            'branch_short_name'       => 'nullable|string|max:50',
            'phone'                   => 'nullable|string|max:30',
            'deliver_to'              => 'nullable|string|max:100',
            'address'                 => 'nullable|string',
            'sales_person_id'         => 'nullable|integer',
            'sales_area_id'           => 'nullable|integer',
            'sales_group_id'          => 'nullable|integer',
            'location_id'             => 'nullable|integer',
            'shipping_company_id'     => 'nullable|integer',
            'tax_group_id'            => 'nullable|integer',
            'sales_account'           => 'nullable|string|max:15',
            'sales_discount_account'  => 'nullable|string|max:15',
            'ar_account'              => 'nullable|string|max:15',
            'prompt_discount_account' => 'nullable|string|max:15',
            'bank_account_number'     => 'nullable|string|max:50',
            'contact_person'          => 'nullable|string|max:100',
            'secondary_phone'         => 'nullable|string|max:30',
            'fax'                     => 'nullable|string|max:30',
            'email'                   => 'nullable|string|max:100',
            'document_language'       => 'nullable|string|max:30',
            'mailing_address'         => 'nullable|string',
            'billing_address'         => 'nullable|string',
            'general_notes'           => 'nullable|string',
        ]);

        $branch->update($data);

        return ApiResponse::updated($branch->fresh(), 'Branch updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $branch = CustomerBranch::find($id);

        if (! $branch) {
            return ApiResponse::notFound('Branch not found');
        }

        $branch->delete();

        return ApiResponse::deleted('Branch deleted');
    }
}
