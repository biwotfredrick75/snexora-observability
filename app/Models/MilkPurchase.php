<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilkPurchase extends Model
{
    protected $fillable = [
        'seq', 'grader_id', 'route_id', 'shift_id', 'pricing_type', 'source', 'reference_no',
        'invoice_date', 'due_date', 'total_qty', 'total_amount', 'status',
        'created_by', 'approved_by', 'reject_reason',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'total_qty'    => 'float',
        'total_amount' => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MilkPurchaseItem::class, 'purchase_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(MilkRoute::class, 'route_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MilkCollectionShift::class, 'shift_id');
    }

    public function graderLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'grader_id');
    }
}
