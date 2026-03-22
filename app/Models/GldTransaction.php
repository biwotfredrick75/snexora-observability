<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GldTransaction extends Model
{
    protected $table = 'gld_transactions';

    protected $fillable = [
        'trans_no', 'type', 'tran_date', 'account_code',
        'reference', 'narration', 'amount',
        'created_by', 'dimension_id', 'dimension2_id',
    ];
}
