<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLocation extends Model
{
    protected $table = 'inventory_locations';

    protected $fillable = [
        'code', 'name', 'type', 'contact', 'phone', 'phone2', 'fax',
        'email', 'till_no', 'price_list', 'drive_name',
        'returns_store', 'loading_order', 'inactive',
    ];

    protected $casts = [
        'returns_store' => 'boolean',
        'loading_order' => 'boolean',
        'inactive'      => 'boolean',
    ];

    public function reorderLevels(): HasMany
    {
        return $this->hasMany(ItemReorderLevel::class, 'location_id');
    }
}
