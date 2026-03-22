<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountTransferSlip extends Model
{
    protected $fillable = [
        'slip_no', 'transfer_date', 'reason', 'relationship', 'death_cert_no',
        'old_supplier_id', 'new_owner_name', 'new_owner_id_no', 'dob', 'gender',
        'phone', 'email', 'nok_name', 'nok_id_no', 'nok_phone', 'nok_relationship',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'dob'           => 'date',
    ];
}
