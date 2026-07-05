<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EspCompanyPurchase extends Model
{
    protected $table = 'esp_company_purchases';

    protected $fillable = [
        'purchase_no', 'esp_id', 'purchase_date',
        'total_amount', 'deducted_amount', 'balance', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'purchase_date'   => 'date',
        'total_amount'    => 'decimal:2',
        'deducted_amount' => 'decimal:2',
        'balance'         => 'decimal:2',
    ];

    public function esp(): BelongsTo
    {
        return $this->belongsTo(EspProvider::class, 'esp_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(EspCompanyPurchaseItem::class, 'purchase_id');
    }
}
