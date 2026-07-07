<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierDebitNoteItem extends Model
{
    protected $table = 'supplier_debit_note_items';

    protected $fillable = [
        'sdn_id', 'po_id', 'grn_no', 'stock_id', 'description',
        'qty_to_debit', 'price_before_tax', 'line_total',
    ];
}
