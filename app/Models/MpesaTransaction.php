<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MpesaTransaction extends Model
{
    protected $table      = 'mpesa_payments';
    protected $primaryKey = 'Auto';
    public    $timestamps = false; // table uses trans_date / transfer_time, not created_at/updated_at

    protected $fillable = [
        'TransactionType', 'TransID', 'TransTime', 'TransAmount',
        'BusinessShortCode', 'BilRef', 'MSISDN',
        'First_Name', 'Middle_Name', 'Last_Name',
        'OrgAccountBalance', 'custBalance', 'allocated',
        'trans_date', 'transfer_user', 'transfer_time',
        'debtor_no', 'payment_id',
    ];

    protected $casts = [
        'TransAmount'       => 'float',
        'OrgAccountBalance' => 'float',
        'custBalance'       => 'float',
        'allocated'         => 'float',
        'trans_date'        => 'datetime',
    ];

    // ── Derived: status based on transfer_user ─────────────────────────────
    public function getStatusAttribute(): string
    {
        return (isset($this->attributes['transfer_user']) && $this->attributes['transfer_user'] !== '')
            ? 'transferred'
            : 'pending';
    }

    // ── Derived: full name ─────────────────────────────────────────────────
    public function getNameAttribute(): string
    {
        return trim("{$this->First_Name} {$this->Middle_Name} {$this->Last_Name}");
    }

    // ── Derived: formatted date ────────────────────────────────────────────
    public function getTransactionDateAttribute(): ?string
    {
        return $this->trans_date?->toDateString();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'payment_id', 'id');
    }
}
