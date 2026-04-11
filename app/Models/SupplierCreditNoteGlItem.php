<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierCreditNoteGlItem extends Model
{
    protected $table = 'supplier_credit_note_gl_items';

    protected $fillable = [
        'scn_id', 'account_code', 'account_name',
        'dimension_id', 'dimension2_id', 'amount', 'memo',
    ];
}
