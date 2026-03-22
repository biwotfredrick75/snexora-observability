<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithholdingTax extends Model
{
    protected $fillable = ['gl_account', 'description', 'tax_rate', 'inactive'];

    protected $casts = [
        'tax_rate' => 'float',
        'inactive' => 'boolean',
    ];
}
