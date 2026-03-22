<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesPerson extends Model
{
    protected $table    = 'sales_persons';
    protected $fillable = ['name', 'telephone', 'fax', 'email', 'provision_pct', 'turnover_break_pt', 'provision2_pct', 'username'];
    protected $casts    = ['provision_pct' => 'float', 'turnover_break_pt' => 'float', 'provision2_pct' => 'float'];
}
