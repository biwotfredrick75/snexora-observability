<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrder extends Model
{
    protected $fillable = [
        'so_no', 'debtor_no', 'branch_id', 'order_date', 'delivery_date',
        'payment_terms', 'price_list_id', 'shipping_charge', 'sub_total',
        'amount_total', 'status', 'dimension_id', 'dimension2_id',
        'location_id', 'vehicle', 'shift', 'deliver_to', 'address',
        'contact_phone', 'customer_ref', 'comments', 'shipping_company_id', 'created_by',
    ];

    protected $casts = [
        'order_date'      => 'date',
        'delivery_date'   => 'date',
        'shipping_charge' => 'float',
        'sub_total'       => 'float',
        'amount_total'    => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'so_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public static function nextSoNo(): string
    {
        $year = now()->year;
        $last = static::where('so_no', 'like', "SO%/{$year}")
            ->lockForUpdate()
            ->pluck('so_no')
            ->sortByDesc(fn($n) => (int) substr($n, 2, strpos($n, '/') - 2))
            ->first();

        $seq = 1;
        if ($last) {
            $seq = (int) substr($last, 2, strpos($last, '/') - 2) + 1;
        }

        return 'SO' . str_pad($seq, 3, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
