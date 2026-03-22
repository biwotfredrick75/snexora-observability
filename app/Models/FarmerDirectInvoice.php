<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class FarmerDirectInvoice extends Model
{
    protected $fillable = [
        'seq', 'inv_no', 'farmer_id', 'invoice_date', 'due_date', 'payment_terms',
        'no_loading_order', 'shipping_charge', 'sub_total', 'amount_total',
        'vehicle', 'shift_id', 'deliver_to', 'address', 'contact_phone',
        'customer_ref', 'comments', 'shipping_company_id', 'status', 'created_by',
    ];

    protected $casts = [
        'invoice_date'    => 'date',
        'due_date'        => 'date',
        'no_loading_order'=> 'boolean',
        'shipping_charge' => 'float',
        'sub_total'       => 'float',
        'amount_total'    => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(FarmerDirectInvoiceItem::class, 'invoice_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }

    public static function nextInvNo(): string
    {
        $year = now()->year;
        $last = static::lockForUpdate()
            ->where('inv_no', 'like', "INV/%/{$year}")
            ->max('inv_no');

        $seq = 0;
        if ($last) {
            $parts = explode('/', $last);
            $seq   = (int) ($parts[1] ?? 0);
        }

        return 'INV/' . str_pad($seq + 1, 5, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
