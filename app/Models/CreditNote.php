<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNote extends Model
{
    protected $fillable = [
        'cn_no', 'inv_id', 'cn_type', 'debtor_no', 'branch_id', 'customer_credit_ref',
        'sales_type_id', 'shipping_company_id', 'shipping_charge',
        'dimension_id', 'dimension2_id',
        'cn_date', 'reason_id', 'location_id',
        'sub_total', 'amount_total', 'write_off_amount',
        'unallocated_amount', 'allocated_amount',
        'status', 'comments', 'created_by',
        'etims_status', 'etims_invc_no', 'etims_rpt_no', 'etims_intr_data', 'etims_rct_sign',
        'etims_rcpt_date', 'etims_mrc_no', 'etims_qr_path', 'etims_stamped_at', 'etims_error',
    ];

    protected $casts = [
        'cn_date'            => 'date',
        'sub_total'          => 'float',
        'amount_total'       => 'float',
        'write_off_amount'   => 'float',
        'shipping_charge'    => 'float',
        'unallocated_amount' => 'float',
        'allocated_amount'   => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class, 'cn_id');
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
        return $this->belongsTo(CreditNoteReason::class, 'reason_id');
    }

    public static function nextCnNo(): string
    {
        $year = now()->year;
        $last = static::where('cn_no', 'like', "CN/%/{$year}")
            ->lockForUpdate()
            ->pluck('cn_no')
            ->sortByDesc(fn($n) => (int) (explode('/', $n)[1] ?? 0))
            ->first();

        $seq = 1;
        if ($last) {
            $parts = explode('/', $last);
            $seq = (int) ($parts[1] ?? 0) + 1;
        }

        return 'CN/' . str_pad($seq, 3, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
