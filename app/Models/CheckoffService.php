<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoffService extends Model
{
    protected $fillable = ['gl_account', 'service_type', 'service_name', 'active'];

    protected $casts = ['active' => 'boolean'];
}
