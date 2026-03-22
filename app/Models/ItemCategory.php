<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemCategory extends Model
{
    protected $fillable = [
        'name', 'description', 'inactive',
        'item_tax_type', 'item_type', 'units_of_measure',
        'exclude_from_sales', 'exclude_from_purchases',
        'sales_gl_account', 'inventory_gl_account', 'cogs_gl_account',
        'inventory_adjustments_gl_account', 'item_assembly_costs_gl_account',
        'dimension1', 'dimension2',
    ];

    protected $casts = [
        'inactive'               => 'boolean',
        'exclude_from_sales'     => 'boolean',
        'exclude_from_purchases' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
    }
}
