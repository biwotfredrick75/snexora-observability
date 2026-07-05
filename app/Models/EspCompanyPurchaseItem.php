<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EspCompanyPurchaseItem extends Model
{
    public $timestamps = false;
    protected $table = 'esp_company_purchase_items';

    protected $fillable = ['purchase_id', 'description', 'qty', 'unit_price', 'total'];

    protected $casts = [
        'qty'        => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(EspCompanyPurchase::class, 'purchase_id');
    }
}
