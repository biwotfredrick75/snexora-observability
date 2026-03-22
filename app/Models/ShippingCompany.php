<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingCompany extends Model
{
    protected $fillable = [
        'name', 'contact_person', 'phone', 'secondary_phone', 'address', 'inactive',
    ];

    protected $casts = [
        'inactive' => 'boolean',
    ];
}
