<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseQuotationSupplier extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'quotation_id', 'supplier_id', 'status',
        'received_date', 'total_amount', 'notes',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplierId');
    }

    public function prices()
    {
        return $this->hasMany(PurchaseQuotationItemPrice::class, 'quotation_supplier_id');
    }
}
