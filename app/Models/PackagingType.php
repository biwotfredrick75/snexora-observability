<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingType extends Model
{
    protected $fillable = ['code', 'name', 'category', 'capacity', 'description', 'inactive'];
    protected $casts    = ['inactive' => 'boolean', 'capacity' => 'float'];
}
