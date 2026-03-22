<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTerm extends Model
{
    protected $fillable = [
        'description', 'type', 'due_after_days', 'inactive',
    ];

    protected $casts = [
        'inactive'       => 'boolean',
        'due_after_days' => 'integer',
    ];
}
