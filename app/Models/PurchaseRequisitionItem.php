<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisitionItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'pr_id', 'stock_id', 'description', 'qty', 'unit_of_measure', 'line_total',
    ];
}
