<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CustomerPayment extends Model
{
    protected $fillable = [
        'payment_no', 'debtor_no', 'payment_date',
        'bank_account_code', 'payment_type',
        'amount', 'discount_amount', 'bank_charge',
        'allocated_amount', 'unallocated_amount',
        'reference', 'memo', 'status', 'created_by',
    ];

    protected $casts = [
        'payment_date'       => 'date',
        'amount'             => 'float',
        'discount_amount'    => 'float',
        'bank_charge'        => 'float',
        'allocated_amount'   => 'float',
        'unallocated_amount' => 'float',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DebtorAllocation::class, 'source_id')
            ->where('source_type', 'payment');
    }

    public static function nextPaymentNo(): string
    {
        $year = now()->year;

        $last = static::where('payment_no', 'like', "RCP/%/{$year}")
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(payment_no, \'/\', 2), \'/\', -1) AS UNSIGNED) DESC')
            ->value('payment_no');

        $seq = 1;
        if ($last) {
            $parts = explode('/', $last);
            $seq   = (int) ($parts[1] ?? 0) + 1;
        }

        return 'RCP/' . str_pad($seq, 3, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
