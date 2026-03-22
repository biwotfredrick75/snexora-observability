<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MilkQaParameter extends Model
{
    protected $fillable = ['parameter_name', 'data_type', 'display_order', 'active'];

    protected $casts = [
        'active'        => 'boolean',
        'display_order' => 'integer',
    ];
}
