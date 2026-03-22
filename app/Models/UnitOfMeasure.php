<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    protected $table    = 'units_of_measure';
    protected $fillable = ['unit', 'description', 'decimal_places', 'inactive'];
    protected $casts    = ['inactive' => 'boolean'];
}
