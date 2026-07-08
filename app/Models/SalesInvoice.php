<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Casts\Attribute;

class SalesInvoice extends Model
{
    protected $fillable = [
        'inv_no', 'dn_id', 'so_id', 'debtor_no', 'customer_name', 'pos_trans_id',
        'branch_id', 'invoice_date', 'due_date',
        'payment_terms', 'price_list_id', 'shipping_charge', 'sub_total',
        'amount_total', 'status', 'dimension_id', 'dimension2_id',
        'location_id', 'vehicle', 'shift', 'deliver_to', 'address',
        'contact_phone', 'customer_ref', 'comments', 'payment_provider', 'payment_channel_code',
        'shipping_company_id', 'created_by',
        'etims_status', 'etims_invc_no', 'etims_rpt_no', 'etims_intr_data', 'etims_rct_sign',
        'etims_rcpt_date', 'etims_mrc_no', 'etims_qr_path', 'etims_stamped_at', 'etims_error',
    ];

    protected $casts = [
        'invoice_date'    => 'date',
        'due_date'        => 'date',
        'shipping_charge' => 'float',
        'sub_total'       => 'float',
        'amount_total'    => 'float',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'inv_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(SalesDelivery::class, 'dn_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(DebtorAllocation::class, 'inv_id');
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return round($this->amount_total - $this->allocations()->sum('amount'), 2);
    }

    public static function nextInvNo(): string
    {
        $year = now()->year;
        $last = static::lockForUpdate()
            ->where('inv_no', 'like', "INV/%/{$year}")
            ->max('inv_no');

        $seq = 0;
        if ($last) {
            $parts = explode('/', $last);
            $seq = (int) ($parts[1] ?? 0);
        }

        return 'INV/' . str_pad($seq + 1, 5, '0', STR_PAD_LEFT) . "/{$year}";
    }
}
