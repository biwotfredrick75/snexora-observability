<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrLetterTemplate extends Model
{
    protected $fillable = ['name', 'category', 'body', 'inactive'];

    protected $casts = ['inactive' => 'boolean'];
}
