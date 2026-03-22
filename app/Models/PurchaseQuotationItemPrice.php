<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseQuotationItemPrice extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'quotation_supplier_id', 'stock_id',
        'unit_price', 'discount_amt', 'line_total',
    ];
}
