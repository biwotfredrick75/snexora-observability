<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Supplier::where('inactive', false);

        if ($request->type) {
            $q->where('supplierType', $request->type);
        }

        if ($request->search) {
            $q->where(function ($q2) use ($request) {
                $q2->where('supplierName',       'like', "%{$request->search}%")
                   ->orWhere('supplierReference', 'like', "%{$request->search}%")
                   ->orWhere('memberNumber',      'like', "%{$request->search}%")
                   ->orWhere('contactPerson',     'like', "%{$request->search}%");
            });
        }

        $perPage = min(max((int) $request->get('per_page', 50), 10), 500);

        $suppliers = $q->orderBy('supplierName')
            ->select(['supplierId', 'supplierName', 'supplierReference', 'memberNumber',
                      'contactPerson', 'currencyCode', 'creditLimit', 'paymentTerms',
                      'supplierType', 'inactive', 'route'])
            ->paginate($perPage);

        return ApiResponse::paginated($suppliers, 'Suppliers retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Core
            'supplierName'           => 'required|string|max:60',
            'supplierReference'      => 'required|string|max:100|unique:suppliers,supplierReference',
            'memberNumber'           => 'nullable|string|max:200|unique:suppliers,memberNumber',
            'supplierType'           => 'nullable|string|max:200',
            'contactPerson'          => 'nullable|string|max:60',
            'gender'                 => 'nullable|string|max:200',
            'dateOfBirth'            => 'nullable|date',
            'idNumber'               => 'nullable|string|max:200',
            'gstNumber'              => 'nullable|string|max:25',
            'website'                => 'nullable|string|max:100',
            'notes'                  => 'nullable|string',
            'inactive'               => 'boolean',
            // Address
            'address'                => 'nullable|string',
            'supplierAddress'        => 'nullable|string',
            // Financial
            'currencyCode'           => 'nullable|string|max:3',
            'paymentTerms'           => 'nullable|integer',
            'creditLimit'            => 'nullable|numeric|min:0',
            'taxIncluded'            => 'boolean',
            'purchaseAccount'        => 'nullable|string|max:15',
            'payableAccount'         => 'nullable|string|max:15',
            'paymentDiscountAccount' => 'nullable|string|max:15',
            'withholdingTaxAccount'  => 'nullable|string|max:100',
            // Bank
            'bankAccount'            => 'nullable|string|max:60',
            'bankNumber'             => 'nullable|string|max:200',
            'bankBranch'             => 'nullable|string|max:200',
            'supplierAccountNumber'  => 'nullable|string|max:40',
            // Route / Farmer
            'routeGroupId'           => 'nullable|integer',
            'route'                  => 'nullable|string|max:50',
            'packingSharesLimit'     => 'nullable|numeric|min:0',
            'sharesLimit'            => 'nullable|numeric|min:0',
            'savingsPerLitre'        => 'nullable|numeric|min:0',
            'deductNhif'             => 'boolean',
            'registrationFeeComplete'=> 'nullable|integer',
            'balanceMessageDate'     => 'nullable|date',
            // Next of Kin
            'nextOfKin'              => 'nullable|string|max:200',
            'nextOfKinId'            => 'nullable|string|max:200',
            'nextOfKinRelationship'  => 'nullable|string|max:200',
            'nextOfKinTelephone'     => 'nullable|string|max:250',
        ]);

        // Defaults for required non-nullable columns
        $data['memberNumber']        = $data['memberNumber']    ?? $data['supplierReference'];
        $data['currencyCode']        = $data['currencyCode']    ?? 'KES';
        $data['supplierType']        = $data['supplierType']    ?? 'Normal';
        $data['routeGroupId']        = $data['routeGroupId']    ?? 0;
        $data['supplierAddress']     = $data['supplierAddress'] ?? $data['address'] ?? '';
        $data['address']             = $data['address']         ?? '';
        $data['contactPerson']       = $data['contactPerson']   ?? '';
        $data['notes']               = $data['notes']           ?? '';
        $data['gender']              = $data['gender']          ?? '';
        $data['dateOfBirth']         = $data['dateOfBirth']     ?? '2000-01-01';
        $data['idNumber']            = $data['idNumber']        ?? '';
        $data['bankNumber']          = $data['bankNumber']      ?? '';
        $data['bankBranch']          = $data['bankBranch']      ?? '';
        $data['nextOfKin']           = $data['nextOfKin']       ?? '';
        $data['nextOfKinId']         = $data['nextOfKinId']     ?? '';
        $data['nextOfKinRelationship'] = $data['nextOfKinRelationship'] ?? '';
        $data['route']               = $data['route']           ?? '';
        $data['packingSharesLimit']  = $data['packingSharesLimit']  ?? 0;
        $data['sharesLimit']         = $data['sharesLimit']         ?? 0;
        $data['balanceMessageDate']  = $data['balanceMessageDate']  ?? now()->toDateString();
        $data['isVendor']            = true;

        try {
            $supplier = Supplier::create($data);
        } catch (QueryException $e) {
            Log::error('Supplier creation failed: ' . $e->getMessage());
            return ApiResponse::error(
                'Could not save the supplier — one of the fields has an invalid or missing value. Please review the form and try again.',
                422
            );
        }

        return ApiResponse::created($supplier, 'Supplier created');
    }

    public function show(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        return ApiResponse::success($supplier, 'Supplier retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);

        $data = $request->validate([
            // Core
            'supplierName'           => 'required|string|max:60',
            'memberNumber'           => "nullable|string|max:200|unique:suppliers,memberNumber,{$id},supplierId",
            'supplierType'           => 'nullable|string|max:200',
            'contactPerson'          => 'nullable|string|max:60',
            'gender'                 => 'nullable|string|max:200',
            'dateOfBirth'            => 'nullable|date',
            'idNumber'               => 'nullable|string|max:200',
            'gstNumber'              => 'nullable|string|max:25',
            'website'                => 'nullable|string|max:100',
            'notes'                  => 'nullable|string',
            'inactive'               => 'boolean',
            // Address
            'address'                => 'nullable|string',
            'supplierAddress'        => 'nullable|string',
            // Financial
            'currencyCode'           => 'nullable|string|max:3',
            'paymentTerms'           => 'nullable|integer',
            'creditLimit'            => 'nullable|numeric|min:0',
            'taxIncluded'            => 'boolean',
            'purchaseAccount'        => 'nullable|string|max:15',
            'payableAccount'         => 'nullable|string|max:15',
            'paymentDiscountAccount' => 'nullable|string|max:15',
            'withholdingTaxAccount'  => 'nullable|string|max:100',
            // Bank
            'bankAccount'            => 'nullable|string|max:60',
            'bankNumber'             => 'nullable|string|max:200',
            'bankBranch'             => 'nullable|string|max:200',
            'supplierAccountNumber'  => 'nullable|string|max:40',
            // Route / Farmer
            'routeGroupId'           => 'nullable|integer',
            'route'                  => 'nullable|string|max:50',
            'packingSharesLimit'     => 'nullable|numeric|min:0',
            'sharesLimit'            => 'nullable|numeric|min:0',
            'savingsPerLitre'        => 'nullable|numeric|min:0',
            'deductNhif'             => 'boolean',
            'registrationFeeComplete'=> 'nullable|integer',
            'balanceMessageDate'     => 'nullable|date',
            // Next of Kin
            'nextOfKin'              => 'nullable|string|max:200',
            'nextOfKinId'            => 'nullable|string|max:200',
            'nextOfKinRelationship'  => 'nullable|string|max:200',
            'nextOfKinTelephone'     => 'nullable|string|max:250',
        ]);

        if (isset($data['address']) && !isset($data['supplierAddress'])) {
            $data['supplierAddress'] = $data['address'];
        }
        // contactPerson/notes are NOT NULL at the DB level (contactPerson has a
        // '' default, notes has none at all) — an explicit null here (empty
        // field submitted) would still violate that.
        foreach (['contactPerson', 'notes'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                $data[$col] = '';
            }
        }

        try {
            $supplier->update($data);
        } catch (QueryException $e) {
            Log::error('Supplier update failed: ' . $e->getMessage());
            return ApiResponse::error(
                'Could not save the supplier — one of the fields has an invalid or missing value. Please review the form and try again.',
                422
            );
        }

        return ApiResponse::updated($supplier, 'Supplier updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update(['inactive' => true]);
        return ApiResponse::deleted('Supplier deactivated');
    }
}
