<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $primaryKey = 'supplierId';
    public $timestamps = false;

    protected $fillable = [
        'supplierName', 'supplierReference', 'memberNumber', 'routeGroupId',
        'address', 'supplierAddress', 'gstNumber', 'contactPerson',
        'supplierAccountNumber', 'website', 'bankAccount', 'currencyCode',
        'paymentTerms', 'taxIncluded', 'dimensionId', 'dimension2Id',
        'taxGroupId', 'creditLimit', 'purchaseAccount', 'payableAccount',
        'paymentDiscountAccount', 'notes', 'inactive', 'supplierType',
        'gender', 'dateOfBirth', 'bankNumber', 'bankBranch', 'idNumber',
        'nextOfKin', 'nextOfKinId', 'nextOfKinRelationship', 'route',
        'deductNhif', 'packingSharesLimit', 'savingsPerLitre',
        'withholdingTaxAccount', 'nextOfKinTelephone', 'source',
        'registrationFeeComplete', 'sharesLimit', 'balanceMessageDate', 'isVendor',
    ];

    protected $casts = [
        'inactive' => 'boolean',
        'isVendor' => 'boolean',
        'taxIncluded' => 'boolean',
        'deductNhif' => 'boolean',
    ];
}
