<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'plate_no', 'make', 'model', 'year', 'type', 'color', 'capacity', 'inactive',
    ];

    protected $casts = [
        'year'     => 'integer',
        'capacity' => 'integer',
        'inactive' => 'boolean',
    ];

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }
}
