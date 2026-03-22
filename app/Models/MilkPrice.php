<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MilkPrice extends Model
{
    protected $fillable = ['price_type', 'from_qty', 'to_qty', 'price', 'date_from', 'date_to'];

    protected $casts = [
        'from_qty'  => 'decimal:2',
        'to_qty'    => 'decimal:2',
        'price'     => 'decimal:4',
        'date_from' => 'date',
        'date_to'   => 'date',
    ];
}
