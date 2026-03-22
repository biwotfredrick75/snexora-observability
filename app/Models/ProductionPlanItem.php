<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionPlanItem extends Model
{
    protected $fillable = [
        'production_plan_id', 'stock_id', 'description',
        'current_stock', 'planned_qty', 'actual_qty',
    ];

    public function plan()
    {
        return $this->belongsTo(ProductionPlan::class, 'production_plan_id');
    }
}
