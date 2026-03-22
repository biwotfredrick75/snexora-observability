<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    // Transaction type constants
    const TYPE_GRN         = 20;
    const TYPE_TRANSFER    = 13;
    const TYPE_ADJUSTMENT  = 16;
    const TYPE_REQUISITION = 17;
    const TYPE_CONSUMABLE  = 18;
    const TYPE_DELIVERY    = 26;
    const TYPE_INVOICE     = 10;  // direct sales invoice GL posting
    const TYPE_CREDIT_NOTE = 11;  // credit note (reversal of invoice)

    protected $table      = 'stock_movements';
    protected $primaryKey = 'trans_id';
    public    $timestamps = false;

    protected $fillable = [
        'trans_no', 'stock_id', 'type', 'loc_code', 'loc_code_from',
        'tran_date', 'date_moved', 'qty', 'price', 'standard_cost',
        'reference', 'comments', 'user_name', 'vehicle', 'shift',
        'route_id', 'tid', 'tidd', 'ref_no', 'gate_pass_no', 'batch_no',
        'approved', 'unique_key',
    ];
}
