<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    protected $fillable = [
        'pr_no', 'reference', 'branch', 'requisition_date', 'due_date',
        'status', 'raised_by', 'hod_approval_by', 'finance_approval_by',
        'ceo_approval_by', 'hod_approval_date', 'finance_approval_date',
        'ceo_approval_date', 'amount', 'comments',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseRequisitionItem::class, 'pr_id');
    }
}
