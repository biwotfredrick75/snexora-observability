<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactCategory extends Model
{
    protected $fillable = [
        'category_type', 'category_subtype', 'short_name', 'description', 'inactive',
    ];

    protected $casts = [
        'inactive' => 'boolean',
    ];
}
