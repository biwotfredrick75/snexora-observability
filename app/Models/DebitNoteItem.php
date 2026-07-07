<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebitNoteItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'dn_id', 'stock_id', 'description', 'qty', 'unit', 'price', 'line_total',
    ];

    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(DebitNote::class, 'dn_id');
    }
}
