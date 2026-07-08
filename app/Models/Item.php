<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    protected $primaryKey  = 'stock_id';
    public    $keyType     = 'string';
    public    $incrementing = false;
    public    $timestamps   = false;

    protected $fillable = [
        'stock_id', 'category_id', 'tax_type_id',
        'description', 'long_description',
        'units', 'mb_flag', 'supplier_id',
        // GL Accounts
        'sales_account', 'cogs_account', 'inventory_account',
        'adjustment_account', 'wip_account',
        // Dimensions
        'dimension_id', 'dimension2_id',
        // Costs
        'purchase_cost', 'material_cost', 'labour_cost', 'overhead_cost', 'standard_cost',
        // Status flags
        'inactive', 'no_sale', 'no_purchase', 'editable',
        // Depreciation
        'depreciation_method', 'depreciation_rate', 'depreciation_factor',
        'depreciation_start', 'depreciation_date', 'fa_class_id',
        // Other
        'bar_code', 'apply_batching', 'hs_code', 'material_weight',
        'item_commission', 'sell_in_pieces', 'sub_uoms',
        'ai_item', 'treatment_item', 'image_filename',
        // eTIMS
        'etims_item_name', 'etims_stock_category', 'etims_item_type',
        'etims_taxation_type', 'etims_item_code', 'etims_stock_class',
        'etims_packaging_unit', 'etims_quantity_unit', 'etims_origin_nation',
    ];

    protected $casts = [
        'inactive'    => 'boolean',
        'no_sale'     => 'boolean',
        'no_purchase' => 'boolean',
        'editable'    => 'boolean',
        'apply_batching' => 'boolean',
        'ai_item'        => 'boolean',
        'treatment_item' => 'boolean',
        'purchase_cost'  => 'float',
        'material_cost'  => 'float',
        'labour_cost'    => 'float',
        'overhead_cost'  => 'float',
        'material_weight'=> 'float',
        'depreciation_rate'  => 'float',
        'depreciation_factor'=> 'float',
        'item_commission'    => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /**
     * Items eligible to appear in sales pickers: not individually flagged
     * no_sale, not inactive, and not under a category flagged
     * exclude_from_sales. Uses whereDoesntHave (not whereHas) so items with
     * no category, or an orphaned/invalid category_id, are still included —
     * only a category that actually exists and is excluded hides them.
     */
    public function scopeSalable($query)
    {
        return $query->where('no_sale', false)
            ->where('inactive', false)
            ->whereDoesntHave('category', fn ($q) => $q->where('exclude_from_sales', true));
    }
}
