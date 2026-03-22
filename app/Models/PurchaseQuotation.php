<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseQuotation extends Model
{
    protected $fillable = [
        'quotation_no', 'pr_id', 'reference', 'quotation_date',
        'due_date', 'location_id', 'status', 'raised_by', 'notes',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseQuotationItem::class, 'quotation_id');
    }

    public function suppliers()
    {
        return $this->hasMany(PurchaseQuotationSupplier::class, 'quotation_id');
    }

    public function pr()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'pr_id');
    }
}
