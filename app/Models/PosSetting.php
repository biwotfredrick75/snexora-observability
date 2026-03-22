<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosSetting extends Model
{
    protected $fillable = [
        'name', 'allow_credit_sale', 'allow_cash_sale', 'location', 'default_account', 'inactive',
    ];

    protected $casts = [
        'allow_credit_sale' => 'boolean',
        'allow_cash_sale'   => 'boolean',
        'inactive'          => 'boolean',
    ];
}
