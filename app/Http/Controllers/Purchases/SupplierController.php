<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $data['bankAccount']         = $data['bankAccount']     ?? '';
        $data['website']             = $data['website']         ?? '';
        $data['supplierAccountNumber'] = $data['supplierAccountNumber'] ?? '';
        $data['purchaseAccount']     = $data['purchaseAccount']     ?? '';
        $data['payableAccount']      = $data['payableAccount']      ?? '';
        $data['paymentDiscountAccount'] = $data['paymentDiscountAccount'] ?? '';
        $data['nextOfKin']           = $data['nextOfKin']       ?? '';
        $data['nextOfKinId']         = $data['nextOfKinId']     ?? '';
        $data['nextOfKinRelationship'] = $data['nextOfKinRelationship'] ?? '';
        $data['route']               = $data['route']           ?? '';
        $data['packingSharesLimit']  = $data['packingSharesLimit']  ?? 0;
        $data['sharesLimit']         = $data['sharesLimit']         ?? 0;
        $data['balanceMessageDate']  = $data['balanceMessageDate']  ?? now()->toDateString();
        $data['isVendor']            = true;

        try {
            $supplier = DB::transaction(function () use ($data) {
                $data['memberNumber'] = $this->nextMemberNumber();
                return Supplier::create($data);
            });
        } catch (QueryException $e) {
            Log::error('Supplier creation failed: ' . $e->getMessage());
            return ApiResponse::error($this->friendlyDbError($e), 422);
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
        // These columns are NOT NULL at the DB level but have their default
        // ('') only applied when the column is omitted from the INSERT/UPDATE —
        // an explicit null (from a blank form field, converted by the
        // ConvertEmptyStringsToNull middleware) still violates the constraint.
        foreach ([
            'contactPerson', 'notes', 'bankAccount', 'website',
            'supplierAccountNumber', 'purchaseAccount', 'payableAccount',
            'paymentDiscountAccount',
        ] as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                $data[$col] = '';
            }
        }

        try {
            $supplier->update($data);
        } catch (QueryException $e) {
            Log::error('Supplier update failed: ' . $e->getMessage());
            return ApiResponse::error($this->friendlyDbError($e), 422);
        }

        return ApiResponse::updated($supplier, 'Supplier updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $supplier->update(['inactive' => true]);
        return ApiResponse::deleted('Supplier deactivated');
    }

    private const FIELD_LABELS = [
        'supplierName' => 'Supplier Name', 'supplierReference' => 'Reference / Code',
        'memberNumber' => 'Member Number', 'contactPerson' => 'Contact Person',
        'website' => 'Website', 'bankAccount' => 'Bank Account',
        'supplierAccountNumber' => 'Supplier Account Number', 'purchaseAccount' => 'Purchase Account',
        'payableAccount' => 'Payable Account', 'paymentDiscountAccount' => 'Payment Discount Account',
        'gender' => 'Gender', 'dateOfBirth' => 'Date of Birth', 'idNumber' => 'ID / National ID No.',
        'bankNumber' => 'Bank Number', 'bankBranch' => 'Bank Branch', 'route' => 'Route',
        'nextOfKin' => 'Next of Kin', 'nextOfKinId' => 'Next of Kin ID',
        'nextOfKinRelationship' => 'Next of Kin Relationship', 'currencyCode' => 'Currency',
        'supplierType' => 'Supplier Type', 'address' => 'Physical Address',
        'supplierAddress' => 'Postal Address', 'notes' => 'Notes',
    ];

    /**
     * Turns a raw QueryException into a message naming the actual offending
     * field, instead of a generic "something is wrong" catch-all.
     */
    private function friendlyDbError(QueryException $e): string
    {
        $message = $e->getMessage();

        if (preg_match("/Column '(\w+)' cannot be null/", $message, $m)) {
            $label = self::FIELD_LABELS[$m[1]] ?? $m[1];
            return "The \"{$label}\" field is required and was left empty. Please fill it in and try again.";
        }

        if (preg_match("/Duplicate entry '(.*?)' for key '[\\w.]*?(\\w+)'/", $message, $m)) {
            $label = self::FIELD_LABELS[$m[2]] ?? $m[2];
            return "\"{$m[1]}\" is already used by another supplier for \"{$label}\". Please use a different value.";
        }

        if (preg_match("/Data too long for column '(\w+)'/", $message, $m)) {
            $label = self::FIELD_LABELS[$m[1]] ?? $m[1];
            return "The value entered for \"{$label}\" is too long. Please shorten it and try again.";
        }

        return 'Could not save the supplier — one of the fields has an invalid or missing value. Please review the form and try again.';
    }

    /**
     * Next sequential MBR##### number. Legacy bulk-imported rows use
     * MBR<supplierReference> (e.g. MBRFRM030000) which the '^MBR[0-9]+$'
     * pattern deliberately excludes, so this counter starts clean at
     * MBR00001 regardless of how many legacy rows exist.
     */
    private function nextMemberNumber(): string
    {
        $last = DB::table('suppliers')
            ->selectRaw("MAX(CAST(SUBSTRING(memberNumber, 4) AS UNSIGNED)) AS n")
            ->whereRaw("memberNumber REGEXP '^MBR[0-9]+$'")
            ->lockForUpdate()
            ->value('n') ?? 0;

        return 'MBR' . str_pad($last + 1, 5, '0', STR_PAD_LEFT);
    }
}
