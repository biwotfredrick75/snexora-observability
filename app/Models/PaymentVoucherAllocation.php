<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentVoucherAllocation extends Model
{
    protected $fillable = [
        'payment_voucher_id', 'transaction_type',
        'transaction_id', 'this_allocation',
    ];

    protected $casts = [
        'this_allocation' => 'float',
    ];
}
