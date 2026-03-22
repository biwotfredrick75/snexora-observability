<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MilkPricePerMember extends Model
{
    protected $table = 'milk_prices_per_member';

    protected $fillable = ['supplier_id', 'month', 'year', 'begin_date', 'end_date', 'price'];

    protected $casts = [
        'begin_date' => 'date',
        'end_date'   => 'date',
        'price'      => 'decimal:4',
        'year'       => 'integer',
    ];
}
