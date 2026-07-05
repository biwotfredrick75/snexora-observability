<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentVoucher extends Model
{
    protected $fillable = [
        'pvn_no', 'supplier_id', 'bank_account_code',
        'date_paid', 'reference', 'bank_cheque', 'type',
        'withholding_tax_id', 'withholding_tax_amount', 'amount', 'cheque_no',
        'memo', 'status', 'created_by',
    ];

    protected $casts = [
        'date_paid'              => 'date',
        'withholding_tax_amount' => 'float',
        'amount'                 => 'float',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplierId');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentVoucherAllocation::class);
    }

    public function withholdingTax(): BelongsTo
    {
        return $this->belongsTo(WithholdingTax::class, 'withholding_tax_id');
    }

    public static function nextPvnNo(): string
    {
        $year = now()->year;

        $last = static::where('pvn_no', 'like', "PVN%/{$year}")
            ->lockForUpdate()
            ->pluck('pvn_no')
            ->sortByDesc(function ($n) {
                preg_match('/PVN(\d+)\//', $n, $m);
                return (int) ($m[1] ?? 0);
            })
            ->first();

        $seq = 1;

        if ($last) {
            preg_match('/PVN(\d+)\//', $last, $m);
            $seq = isset($m[1]) ? ((int)$m[1] + 1) : 1;
        }

        return 'PVN' . str_pad($seq, 4, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
