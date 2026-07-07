<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DebitNote extends Model
{
    protected $fillable = [
        'dn_no', 'inv_id', 'debtor_no', 'dn_date', 'reason_id', 'location_id',
        'tax_type_id', 'dimension_id', 'dimension2_id',
        'sub_total', 'tax_amount', 'amount_total',
        'allocated_amount', 'unallocated_amount',
        'status', 'comments', 'created_by',
    ];

    protected $casts = [
        'dn_date'            => 'date',
        'sub_total'          => 'float',
        'tax_amount'         => 'float',
        'amount_total'       => 'float',
        'allocated_amount'   => 'float',
        'unallocated_amount' => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DebitNoteItem::class, 'dn_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'inv_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(DebitNoteReason::class, 'reason_id');
    }

    public static function nextDnNo(): string
    {
        $year = now()->year;
        $last = static::where('dn_no', 'like', "DN/%/{$year}")
            ->lockForUpdate()
            ->pluck('dn_no')
            ->sortByDesc(fn ($n) => (int) (explode('/', $n)[1] ?? 0))
            ->first();

        $seq = 1;
        if ($last) {
            $parts = explode('/', $last);
            $seq = (int) ($parts[1] ?? 0) + 1;
        }

        return 'DN/' . str_pad($seq, 3, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
