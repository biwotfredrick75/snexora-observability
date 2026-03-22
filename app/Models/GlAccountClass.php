<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlAccountClass extends Model
{
    // User-defined integer PK — not auto-increment
    public $incrementing = false;
    protected $keyType   = 'int';

    protected $fillable  = ['id', 'name', 'class_type', 'inactive'];

    protected function casts(): array
    {
        return ['inactive' => 'boolean'];
    }

    public function groups()
    {
        return $this->hasMany(GlAccountGroup::class, 'class_id');
    }
}
