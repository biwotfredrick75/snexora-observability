<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EspSale extends Model
{
    protected $table = 'esp_farmer_sales';

    protected $fillable = [
        'sale_no', 'esp_id', 'party_type', 'party_id', 'farmer_id', 'sale_date',
        'total_amount', 'deducted_amount', 'balance', 'status', 'notes', 'created_by',
        'party_deducted', 'party_deducted_at', 'party_deducted_ref',
    ];

    protected $casts = [
        'sale_date'         => 'date',
        'total_amount'      => 'decimal:2',
        'deducted_amount'   => 'decimal:2',
        'balance'           => 'decimal:2',
        'party_deducted'    => 'boolean',
        'party_deducted_at' => 'date',
    ];

    public function esp(): BelongsTo
    {
        return $this->belongsTo(EspProvider::class, 'esp_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EspSaleItem::class, 'sale_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(EspSaleAdjustment::class, 'sale_id');
    }

    public function scopeForParty($query, string $partyType, int $partyId)
    {
        return $query->where('party_type', $partyType)->where('party_id', $partyId);
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', ['pending', 'partial']);
    }

    /** Sales still owed by the billed party (not yet deducted from their own pay). */
    public function scopeNotYetDeducted($query)
    {
        return $query->where('party_deducted', false)->where('status', '!=', 'void');
    }
}
