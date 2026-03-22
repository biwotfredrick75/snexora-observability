<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerDeposit extends Model
{
    protected $fillable = [
        'deposit_no', 'debtor_no', 'bank_account_code',
        'deposit_date', 'deposit_amount',
        'allocated_amount', 'unallocated_amount',
        'reference', 'external_reference', 'branch',
        'memo', 'status', 'created_by',
    ];

    protected $casts = [
        'deposit_date'       => 'date',
        'deposit_amount'     => 'float',
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
            ->where('source_type', 'deposit');
    }

    public static function nextDepositNo(): string
    {
        $year = now()->year;

        $last = static::where('deposit_no', 'like', "DEP/%/{$year}")
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(deposit_no, \'/\', 2), \'/\', -1) AS UNSIGNED) DESC')
            ->value('deposit_no');

        $seq = 1;
        if ($last) {
            $parts = explode('/', $last);
            $seq   = (int) ($parts[1] ?? 0) + 1;
        }

        return 'DEP/' . str_pad($seq, 3, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
