<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EspProvider extends Model
{
    protected $table = 'esp_providers';

    protected $fillable = [
        'esp_code', 'name', 'contact_person', 'phone', 'email',
        'address', 'credit_limit_pct', 'status', 'notes', 'created_by',
    ];

    protected $casts = ['credit_limit_pct' => 'decimal:2'];

    public function farmerSales(): HasMany
    {
        return $this->hasMany(EspFarmerSale::class, 'esp_id');
    }

    public function companyPurchases(): HasMany
    {
        return $this->hasMany(EspCompanyPurchase::class, 'esp_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(EspSettlement::class, 'esp_id');
    }
}
