<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTake extends Model
{
    protected $fillable = [
        'reference', 'location_id', 'date', 'status', 'created_by', 'approved_by', 'memo',
    ];

    public function items()
    {
        return $this->hasMany(StockTakeItem::class);
    }
}
