<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $fillable = [
        'mfg_type_id', 'production_plan_id', 'production_plan_item_id',
        'wo_no', 'product_code', 'product_description',
        'bom_id', 'work_centre_id', 'planned_qty', 'actual_qty_produced', 'unit',
        'start_date', 'due_date', 'completed_date', 'status',
        'location_code', 'output_location_code',
        'total_material_cost', 'total_labour_cost', 'total_overhead_cost',
        'total_scrap_cost', 'total_cost', 'unit_cost',
        'notes', 'mfg_attributes', 'created_by', 'released_by', 'completed_by',
    ];

    protected $casts = [
        'planned_qty'          => 'float',
        'actual_qty_produced'  => 'float',
        'total_material_cost'  => 'float',
        'total_labour_cost'    => 'float',
        'total_overhead_cost'  => 'float',
        'total_scrap_cost'     => 'float',
        'total_cost'           => 'float',
        'unit_cost'            => 'float',
        'start_date'           => 'date',
        'due_date'             => 'date',
        'completed_date'       => 'date',
        'mfg_attributes'       => 'array',
    ];

    public function mfgType(): BelongsTo  { return $this->belongsTo(ManufacturingType::class, 'mfg_type_id'); }
    public function bom(): BelongsTo      { return $this->belongsTo(Bom::class); }
    public function workCentre(): BelongsTo { return $this->belongsTo(WorkCentre::class); }
    public function items(): HasMany      { return $this->hasMany(WorkOrderItem::class)->orderBy('sort_order'); }
    public function labour(): HasMany     { return $this->hasMany(WorkOrderLabour::class)->orderByDesc('work_date'); }
    public function overhead(): HasMany   { return $this->hasMany(WorkOrderOverhead::class)->orderByDesc('date_posted'); }
    public function byproducts(): HasMany { return $this->hasMany(WorkOrderByproduct::class)->orderBy('created_at'); }
}
