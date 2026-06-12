<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'po_id', 'grn_item_id', 'stock_id', 'description', 'qty', 'unit',
        'required_delivery_date', 'price_before_tax', 'discount_amt', 'line_total',
    ];
}
