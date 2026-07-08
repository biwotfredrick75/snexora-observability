<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrChecklistItem extends Model
{
    protected $fillable = [
        'type', 'title', 'description', 'category', 'sort_order', 'inactive',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'inactive'   => 'boolean',
    ];
}
