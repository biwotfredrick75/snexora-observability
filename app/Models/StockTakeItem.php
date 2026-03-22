<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTakeItem extends Model
{
    protected $fillable = ['stock_take_id', 'stock_id', 'system_qty', 'counted_qty', 'unit'];

    public function item()
    {
        return $this->belongsTo(Item::class, 'stock_id', 'stock_id');
    }
}
