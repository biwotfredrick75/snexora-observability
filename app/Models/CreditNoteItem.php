<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'cn_id', 'stock_id', 'description', 'qty', 'unit',
        'price', 'standard_cost', 'discount_pct', 'line_total',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'cn_id');
    }
}
