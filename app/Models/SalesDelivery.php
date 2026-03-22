<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDelivery extends Model
{
    protected $fillable = [
        'dn_no', 'so_id', 'debtor_no', 'branch_id', 'delivery_date', 'invoice_before',
        'payment_terms', 'price_list_id', 'shipping_charge', 'sub_total',
        'amount_total', 'status', 'dimension_id', 'dimension2_id',
        'location_id', 'vehicle', 'shift', 'deliver_to', 'address',
        'contact_phone', 'customer_ref', 'comments', 'shipping_company_id', 'created_by',
        'to_invoice',
    ];

    protected $casts = [
        'delivery_date'   => 'date',
        'invoice_before'  => 'date',
        'shipping_charge' => 'float',
        'sub_total'       => 'float',
        'amount_total'    => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SalesDeliveryItem::class, 'delivery_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public static function nextDnNo(): string
    {
        $year = now()->year;
        $last = static::where('dn_no', 'like', "DN/%/{$year}")
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(dn_no, \'/\', 2), \'/\', -1) AS UNSIGNED) DESC')
            ->value('dn_no');

        $seq = 1;
        if ($last) {
            $parts = explode('/', $last);
            $seq = (int) ($parts[1] ?? 0) + 1;
        }

        return 'DN/' . str_pad($seq, 3, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
