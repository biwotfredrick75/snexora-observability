<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionPlan extends Model
{
    protected $fillable = [
        'mfg_type_id', 'plan_no', 'status', 'production_location',
        'plan_date', 'target_completion_date', 'memo',
        'created_by', 'submitted_by', 'approved_by', 'supervisor_approved_by',
        'submitted_at', 'approved_at', 'supervisor_approved_at',
        'approve_comments', 'reject_comments', 'rejected_by', 'rejected_at',
    ];

    protected $casts = [
        'plan_date'               => 'date:Y-m-d',
        'target_completion_date'  => 'date:Y-m-d',
        'submitted_at'            => 'datetime',
        'approved_at'             => 'datetime',
        'supervisor_approved_at'  => 'datetime',
        'rejected_at'             => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(ProductionPlanItem::class);
    }
}
