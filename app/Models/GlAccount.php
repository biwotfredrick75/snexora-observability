<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlAccount extends Model
{

    protected $table = '0_chart_master';
    protected $fillable = ['account_code', 'account_name', 'account_type', 'inactive'];

    protected function casts(): array
    {
        return [
            'inactive' => 'boolean',
            'tags'     => 'array',
        ];
    }

    public function group()
    {
        return $this->belongsTo(GlAccountGroup::class, 'group_id');
    }
}
