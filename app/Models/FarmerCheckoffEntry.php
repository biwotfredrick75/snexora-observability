<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmerCheckoffEntry extends Model
{
    protected $fillable = [
        'farmer_id', 'month', 'year', 'service_id', 'service_name', 'amount', 'note',
    ];

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(CheckoffService::class, 'service_id');
    }
}
