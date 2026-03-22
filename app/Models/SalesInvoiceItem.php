<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesInvoiceItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'inv_id', 'stock_id', 'description', 'qty', 'unit',
        'price', 'standard_cost', 'discount_pct', 'line_total',
    ];

    protected $casts = [
        'qty'           => 'float',
        'price'         => 'float',
        'standard_cost' => 'float',
        'discount_pct'  => 'float',
        'line_total'    => 'float',
    ];
}
