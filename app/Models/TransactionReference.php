<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionReference extends Model
{
    protected $table = 'transaction_references';

    protected $fillable = [
        'trans_type', 'trans_name', 'prefix', 'pattern',
        'is_default', 'memo', 'inactive', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'inactive'   => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
