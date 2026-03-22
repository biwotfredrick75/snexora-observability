<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderLabour extends Model
{
    protected $table = 'work_order_labour';

    protected $fillable = [
        'work_order_id', 'operator_name', 'role',
        'rate_per_hour', 'hours_worked', 'total_cost', 'work_date', 'created_by',
    ];

    protected $casts = [
        'rate_per_hour' => 'float',
        'hours_worked'  => 'float',
        'total_cost'    => 'float',
        'work_date'     => 'date',
    ];

    public function workOrder(): BelongsTo { return $this->belongsTo(WorkOrder::class); }
}
