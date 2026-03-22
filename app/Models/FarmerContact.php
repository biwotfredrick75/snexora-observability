<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmerContact extends Model
{
    protected $fillable = [
        'farmer_id', 'assignment', 'reference', 'full_name', 'phone', 'secondary_phone', 'fax', 'email',
    ];

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }
}
