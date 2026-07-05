<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPayComponent extends Model
{
    protected $fillable = [
        'name', 'category', 'computation_type', 'default_amount', 'percentage',
        'is_taxable', 'is_statutory', 'gl_account_id', 'active', 'sort_order',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'percentage'      => 'decimal:3',
        'is_taxable'      => 'boolean',
        'is_statutory'    => 'boolean',
        'active'          => 'boolean',
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }
}
