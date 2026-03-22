<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesType extends Model
{
    protected $fillable = ['type_name', 'factor', 'tax_incl'];
    protected $casts    = ['tax_incl' => 'boolean', 'factor' => 'float'];
}
