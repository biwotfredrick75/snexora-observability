<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierDebitNoteGlItem extends Model
{
    protected $table = 'supplier_debit_note_gl_items';

    protected $fillable = [
        'sdn_id', 'account_code', 'account_name',
        'dimension_id', 'dimension2_id', 'amount', 'memo',
    ];
}
