<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemSubcategory extends Model
{
    protected $fillable = ['name', 'inactive'];
    protected $casts    = ['inactive' => 'boolean'];
}
