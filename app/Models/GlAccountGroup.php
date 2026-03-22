<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlAccountGroup extends Model
{
    protected $fillable = ['code', 'name', 'class_id', 'parent_id', 'inactive'];

    protected function casts(): array
    {
        return ['inactive' => 'boolean'];
    }

    public function class()
    {
        return $this->belongsTo(GlAccountClass::class, 'class_id');
    }

    public function parent()
    {
        return $this->belongsTo(GlAccountGroup::class, 'parent_id');
    }

    public function accounts()
    {
        return $this->hasMany(GlAccount::class, 'group_id');
    }
}
