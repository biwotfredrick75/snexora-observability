<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarmerPaymentTerm extends Model
{
    protected $fillable = [
        'description', 'type', 'due_after_days', 'shift', 'inactive',
    ];

    protected $casts = [
        'inactive'       => 'boolean',
        'due_after_days' => 'integer',
    ];
}
