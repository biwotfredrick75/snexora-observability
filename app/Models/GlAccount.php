<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlAccount extends Model
{

    protected $fillable = ['code', 'code2', 'name', 'group_id', 'tags', 'inactive'];

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
