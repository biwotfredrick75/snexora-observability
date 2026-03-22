<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBranch extends Model
{
    protected $fillable = [
        'debtor_no', 'branch_name', 'branch_short_name', 'phone', 'deliver_to', 'address',
        'sales_person_id', 'sales_area_id', 'sales_group_id',
        'location_id', 'shipping_company_id', 'tax_group_id',
        'sales_account', 'sales_discount_account', 'ar_account', 'prompt_discount_account',
        'bank_account_number',
        'contact_person', 'secondary_phone', 'fax', 'email',
        'document_language',
        'mailing_address', 'billing_address', 'general_notes',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public function salesPerson(): BelongsTo
    {
        return $this->belongsTo(SalesPerson::class, 'sales_person_id');
    }

    public function salesArea(): BelongsTo
    {
        return $this->belongsTo(SalesArea::class, 'sales_area_id');
    }

    public function salesGroup(): BelongsTo
    {
        return $this->belongsTo(SalesGroup::class, 'sales_group_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class, 'shipping_company_id');
    }

    public function taxGroup(): BelongsTo
    {
        return $this->belongsTo(TaxGroup::class, 'tax_group_id');
    }
}
