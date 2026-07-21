<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarmerPayment extends Model
{
    protected $table = 'farmer_payments';

    protected $fillable = [
        'farmer_id', 'from_account', 'date_paid', 'reference', 'type',
        'withholding_tax_rate', 'amount_discount', 'amount_payment',
        'bank_charge', 'cheque_no', 'memo', 'created_by',
        'gl_trans_no', 'gl_type',
    ];

    protected $casts = [
        'date_paid'            => 'date',
        'withholding_tax_rate' => 'float',
        'amount_discount'      => 'float',
        'amount_payment'       => 'float',
        'bank_charge'          => 'float',
    ];

    public function farmer()
    {
        return $this->belongsTo(Farmer::class);
    }
}
