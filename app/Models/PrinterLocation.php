<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrinterLocation extends Model
{
    protected $fillable = [
        'name', 'description', 'host', 'port', 'printer_queue', 'timeout', 'inactive',
    ];

    protected $casts = [
        'port'     => 'integer',
        'timeout'  => 'integer',
        'inactive' => 'boolean',
    ];
}
