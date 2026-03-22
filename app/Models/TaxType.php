<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxType extends Model
{
    protected $fillable = [
        'description', 'default_rate', 'sales_gl_account', 'purchasing_gl_account', 'inactive',
    ];

    protected $casts = [
        'default_rate' => 'float',
        'inactive'     => 'boolean',
    ];
}
