<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_no', 'type', 'supplier_id', 'source_grn_id', 'reference', 'supplier_reference',
        'order_date', 'delivery_date', 'due_date', 'location_id', 'receive_into',
        'payment_terms', 'currency', 'exchange_rate', 'status', 'raised_by',
        'hod_approval_by', 'finance_approval_by', 'ceo_approval_by',
        'hod_approval_date', 'finance_approval_date', 'ceo_approval_date',
        'sub_total', 'amount_total', 'customer_memo', 'internal_memo',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class, 'po_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplierId');
    }

    public function sourceGrn()
    {
        return $this->belongsTo(PurchaseOrder::class, 'source_grn_id');
    }
}
