<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingTransfer extends Model
{
    protected $fillable = [
        'transfer_date', 'from_location_id', 'to_location_id', 'comments', 'status', 'created_by',
    ];

    public function items()
    {
        return $this->hasMany(PackagingTransferItem::class);
    }
}
