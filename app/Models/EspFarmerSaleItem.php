<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EspFarmerSaleItem extends Model
{
    public $timestamps = false;
    protected $table = 'esp_farmer_sale_items';

    protected $fillable = ['sale_id', 'description', 'qty', 'unit_price', 'total'];

    protected $casts = [
        'qty'        => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(EspFarmerSale::class, 'sale_id');
    }
}
