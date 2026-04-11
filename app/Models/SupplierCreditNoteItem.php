<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierCreditNoteItem extends Model
{
    protected $table = 'supplier_credit_note_items';

    protected $fillable = [
        'scn_id', 'po_id', 'grn_no', 'stock_id', 'description',
        'qty_received', 'qty_invoiced', 'qty_to_credit',
        'price_before_tax', 'line_total',
    ];
}
