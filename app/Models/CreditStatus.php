<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditStatus extends Model
{
    protected $fillable = ['description', 'disallow_invoices'];
    protected $casts    = ['disallow_invoices' => 'boolean'];
}
