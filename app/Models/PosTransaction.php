<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosTransaction extends Model
{
    protected $fillable = [
        'transaction_no', 'cashier_id', 'customer_name',
        'subtotal', 'tax_amount', 'discount_amount', 'total_amount',
        'payment_method', 'amount_tendered', 'change_amount',
        'mpesa_ref', 'notes', 'status',
        'voided_by', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'subtotal'        => 'float',
        'tax_amount'      => 'float',
        'discount_amount' => 'float',
        'total_amount'    => 'float',
        'amount_tendered' => 'float',
        'change_amount'   => 'float',
        'voided_at'       => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PosTransactionItem::class);
    }

    /** Auto-generate POS transaction number: POS-YYYY-NNNNNN */
    public static function nextTransactionNo(): string
    {
        $year  = now()->format('Y');
        $count = static::whereYear('created_at', $year)->count() + 1;
        return 'POS-' . $year . '-' . str_pad($count, 6, '0', STR_PAD_LEFT);
    }
}
