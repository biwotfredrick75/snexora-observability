<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AccountTransferSlip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountTransferSlipController extends Controller
{
    public function index(): JsonResponse
    {
        $slips = AccountTransferSlip::orderByDesc('transfer_date')->get();
        return ApiResponse::success($slips, 'Transfer slips retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slip_no'          => 'required|string|max:50|unique:account_transfer_slips',
            'transfer_date'    => 'required|date',
            'reason'           => 'nullable|string|max:255',
            'relationship'     => 'nullable|string|max:100',
            'death_cert_no'    => 'nullable|string|max:100',
            'old_supplier_id'  => 'nullable|string|max:100',
            'new_owner_name'   => 'required|string|max:200',
            'new_owner_id_no'  => 'nullable|string|max:50',
            'dob'              => 'nullable|date',
            'gender'           => 'nullable|string|max:20',
            'phone'            => 'nullable|string|max:20',
            'email'            => 'nullable|email|max:100',
            'nok_name'         => 'nullable|string|max:200',
            'nok_id_no'        => 'nullable|string|max:50',
            'nok_phone'        => 'nullable|string|max:20',
            'nok_relationship' => 'nullable|string|max:100',
        ]);
        $slip = AccountTransferSlip::create($data);
        return ApiResponse::created($slip, 'Transfer slip created');
    }

    public function show(int $id): JsonResponse
    {
        $slip = AccountTransferSlip::findOrFail($id);
        return ApiResponse::success($slip, 'Transfer slip retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $slip = AccountTransferSlip::findOrFail($id);
        $data = $request->validate([
            'transfer_date'    => 'sometimes|date',
            'reason'           => 'nullable|string|max:255',
            'relationship'     => 'nullable|string|max:100',
            'death_cert_no'    => 'nullable|string|max:100',
            'old_supplier_id'  => 'nullable|string|max:100',
            'new_owner_name'   => 'sometimes|string|max:200',
            'new_owner_id_no'  => 'nullable|string|max:50',
            'dob'              => 'nullable|date',
            'gender'           => 'nullable|string|max:20',
            'phone'            => 'nullable|string|max:20',
            'email'            => 'nullable|email|max:100',
            'nok_name'         => 'nullable|string|max:200',
            'nok_id_no'        => 'nullable|string|max:50',
            'nok_phone'        => 'nullable|string|max:20',
            'nok_relationship' => 'nullable|string|max:100',
        ]);
        $slip->fill($data)->save();
        return ApiResponse::updated($slip->fresh(), 'Transfer slip updated');
    }

    public function destroy(int $id): JsonResponse
    {
        AccountTransferSlip::findOrFail($id)->delete();
        return ApiResponse::deleted('Transfer slip deleted');
    }
}
