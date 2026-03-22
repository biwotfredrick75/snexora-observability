<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmerDirectInvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'stock_id', 'description', 'qty', 'qty_required',
        'unit', 'location_id', 'price', 'discount_pct', 'standard_cost', 'line_total',
    ];

    protected $casts = [
        'qty'           => 'float',
        'qty_required'  => 'float',
        'price'         => 'float',
        'discount_pct'  => 'float',
        'standard_cost' => 'float',
        'line_total'    => 'float',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FarmerDirectInvoice::class, 'invoice_id');
    }
}
