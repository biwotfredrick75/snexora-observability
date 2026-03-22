<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $fillable = [
        'begin_date',
        'end_date',
        'is_closed',
        'is_current',
    ];

    protected $casts = [
        'begin_date' => 'date',
        'end_date'   => 'date',
        'is_closed'  => 'boolean',
        'is_current' => 'boolean',
    ];
}
