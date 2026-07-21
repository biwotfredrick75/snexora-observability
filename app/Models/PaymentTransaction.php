<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'reference', 'channel', 'status',
        'debtor_no', 'inv_no', 'amount', 'phone', 'bank_account_code',
        'provider_reference', 'merchant_request_id', 'provider_receipt',
        'result_code', 'result_desc',
        'payment_id', 'memo', 'initiated_by',
        'raw_request', 'raw_response', 'completed_at',
    ];

    protected $casts = [
        'amount'       => 'float',
        'raw_request'  => 'array',
        'raw_response' => 'array',
        'completed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'inv_no', 'inv_no');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class, 'payment_id', 'id');
    }

    public static function nextReference(): string
    {
        $year = now()->year;

        $last = static::where('reference', 'like', "PMT/%/{$year}")
            ->lockForUpdate()
            ->pluck('reference')
            ->sortByDesc(fn ($r) => (int) (explode('/', $r)[1] ?? 0))
            ->first();

        $seq = 1;
        if ($last) {
            $parts = explode('/', $last);
            $seq   = (int) ($parts[1] ?? 0) + 1;
        }

        return 'PMT/' . str_pad($seq, 3, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
