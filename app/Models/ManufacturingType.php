<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManufacturingType extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'icon', 'color',
        'process_stages', 'qa_fields', 'output_fields',
        'is_active', 'is_builtin', 'sort_order',
    ];

    protected $casts = [
        'process_stages' => 'array',
        'qa_fields'      => 'array',
        'output_fields'  => 'array',
        'is_active'      => 'boolean',
        'is_builtin'     => 'boolean',
    ];

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'mfg_type_id');
    }
}
