<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EspFarmerSale extends Model
{
    protected $table = 'esp_farmer_sales';

    protected $fillable = [
        'sale_no', 'esp_id', 'farmer_id', 'sale_date',
        'total_amount', 'deducted_amount', 'balance', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'sale_date'       => 'date',
        'total_amount'    => 'decimal:2',
        'deducted_amount' => 'decimal:2',
        'balance'         => 'decimal:2',
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
        return $this->hasMany(EspFarmerSaleItem::class, 'sale_id');
    }
}
