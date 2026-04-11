<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockchainAnchor extends Model
{
    protected $fillable = [
        'trans_type', 'trans_no', 'trans_id',
        'doc_hash', 'tx_hash',
        'network', 'status', 'explorer_url', 'error_message',
        'anchored_at',
    ];

    protected $casts = [
        'anchored_at' => 'datetime',
    ];

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
