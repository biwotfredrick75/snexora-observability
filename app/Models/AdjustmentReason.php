<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdjustmentReason extends Model
{
    protected $fillable = ['code', 'label', 'gl_account', 'inactive'];

    protected $casts = [
        'inactive' => 'boolean',
    ];
}
