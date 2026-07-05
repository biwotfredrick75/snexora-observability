<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CasualWorker extends Model
{
    protected $fillable = [
        'worker_no', 'name', 'national_id', 'phone',
        'emergency_contact', 'emergency_phone', 'trade_id',
        'bank_name', 'account_no', 'mpesa_no', 'status', 'notes',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(CasualWorkerTrade::class, 'trade_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(CasualWorkerAttendance::class, 'worker_id');
    }

    public function payRates(): HasMany
    {
        return $this->hasMany(CasualWorkerPayRate::class, 'worker_id');
    }

    public function payRunItems(): HasMany
    {
        return $this->hasMany(CasualWorkerPayRunItem::class, 'worker_id');
    }

    /** Returns the most recent pay rate effective on or before $date. */
    public function effectiveRate(?string $date = null): ?CasualWorkerPayRate
    {
        $date ??= now()->toDateString();
        return $this->payRates()
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();
    }
}
