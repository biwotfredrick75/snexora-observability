<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderByproduct extends Model
{
    protected $fillable = [
        'work_order_id', 'work_order_stage_id', 'stage_code', 'stage_name',
        'stock_id', 'description', 'qty', 'unit', 'location_code',
        'registered_by', 'stock_movement_id',
    ];

    protected $casts = ['qty' => 'float'];

    public function workOrder(): BelongsTo { return $this->belongsTo(WorkOrder::class); }
}
