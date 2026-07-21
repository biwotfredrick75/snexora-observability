<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    protected $primaryKey = 'debtor_no';
    protected $keyType    = 'string';
    public $incrementing  = false;

    protected $fillable = [
        'debtor_no', 'farmer_no', 'customer_number', 'name', 'short_name', 'address', 'phone', 'email',
        'kra_pin', 'currency',
        'payment_terms', 'price_list_id', 'discount', 'credit_limit',
        'current_credit', 'credit_status_id', 'credit_invoices_allowed',
        'prompt_payment_discount', 'general_notes',
        'dimension_id', 'dimension2_id',
        'inactive', 'latitude', 'longitude',
    ];

    protected $casts = [
        'inactive'                 => 'boolean',
        'discount'                 => 'float',
        'credit_limit'             => 'float',
        'current_credit'           => 'float',
        'credit_invoices_allowed'  => 'integer',
        'prompt_payment_discount'  => 'float',
        'dimension_id'             => 'integer',
        'dimension2_id'            => 'integer',
        'credit_status_id'         => 'integer',
        'latitude'                 => 'float',
        'longitude'                => 'float',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(CustomerBranch::class, 'debtor_no', 'debtor_no');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(SalesType::class, 'price_list_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_terms');
    }

    public function creditStatus(): BelongsTo
    {
        return $this->belongsTo(CreditStatus::class, 'credit_status_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'debtor_no', 'debtor_no');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DebtorAllocation::class, 'debtor_no', 'debtor_no');
    }

    public static function nextCustomerNumber(): int
    {
        return (int) (static::max('customer_number') ?? 0) + 1;
    }
}
