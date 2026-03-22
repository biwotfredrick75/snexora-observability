<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItem extends Model
{
    protected $fillable = [
        'work_order_id', 'component_code', 'description',
        'qty_required', 'qty_issued', 'unit', 'unit_cost', 'waste_pct', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'qty_required' => 'float',
        'qty_issued'   => 'float',
        'unit_cost'    => 'float',
        'waste_pct'    => 'float',
        'line_total'   => 'float',
    ];

    public function workOrder(): BelongsTo { return $this->belongsTo(WorkOrder::class); }
}
