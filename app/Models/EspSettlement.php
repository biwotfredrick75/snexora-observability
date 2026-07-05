<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EspSettlement extends Model
{
    protected $table = 'esp_settlements';

    protected $fillable = [
        'settlement_no', 'esp_id', 'settlement_date', 'period_from', 'period_to',
        'farmer_collections', 'company_purchases_deducted', 'net_payable',
        'actual_paid', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'settlement_date'           => 'date',
        'period_from'               => 'date',
        'period_to'                 => 'date',
        'farmer_collections'        => 'decimal:2',
        'company_purchases_deducted'=> 'decimal:2',
        'net_payable'               => 'decimal:2',
        'actual_paid'               => 'decimal:2',
    ];

    public function esp(): BelongsTo
    {
        return $this->belongsTo(EspProvider::class, 'esp_id');
    }
}
