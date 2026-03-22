<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseQuotationItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'quotation_id', 'stock_id', 'description', 'qty_required', 'unit',
    ];
}
