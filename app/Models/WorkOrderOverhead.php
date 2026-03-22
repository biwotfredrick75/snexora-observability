<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderOverhead extends Model
{
    protected $table = 'work_order_overhead';

    protected $fillable = [
        'work_order_id', 'description', 'overhead_type', 'amount', 'date_posted', 'created_by',
    ];

    protected $casts = [
        'amount'      => 'float',
        'date_posted' => 'date',
    ];

    public function workOrder(): BelongsTo { return $this->belongsTo(WorkOrder::class); }
}
