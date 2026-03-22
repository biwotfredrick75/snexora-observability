<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyDatabase extends Model
{
    protected $fillable = [
        'company', 'host', 'port', 'db_user', 'db_password',
        'db_name', 'collation', 'table_prefix', 'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected $hidden = ['db_password'];
}
