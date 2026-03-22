<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuotation extends Model
{
    protected $fillable = [
        'quot_no', 'debtor_no', 'branch_id', 'quotation_date', 'valid_until',
        'payment_terms', 'price_list_id', 'shipping_charge', 'sub_total',
        'amount_total', 'status', 'so_id', 'dimension_id', 'dimension2_id',
        'location_id', 'vehicle', 'shift', 'deliver_to', 'address',
        'contact_phone', 'customer_ref', 'comments', 'shipping_company_id', 'created_by',
    ];

    protected $casts = [
        'quotation_date'  => 'date',
        'valid_until'     => 'date',
        'shipping_charge' => 'float',
        'sub_total'       => 'float',
        'amount_total'    => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SalesQuotationItem::class, 'quot_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public static function nextQuotNo(): string
    {
        $year = now()->year;
        $last = static::where('quot_no', 'like', "SQ%/{$year}")
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING(quot_no, 3, LOCATE(\'/\', quot_no) - 3) AS UNSIGNED) DESC')
            ->value('quot_no');

        $seq = 1;
        if ($last) {
            $seq = (int) substr($last, 2, strpos($last, '/') - 2) + 1;
        }

        return 'SQ' . str_pad($seq, 3, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
