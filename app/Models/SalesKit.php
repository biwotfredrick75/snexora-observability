<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesKit extends Model
{
    protected $fillable = ['code', 'name', 'inactive'];
    protected $casts    = ['inactive' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(SalesKitItem::class, 'kit_id');
    }
}
