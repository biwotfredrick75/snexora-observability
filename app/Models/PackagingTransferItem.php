<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingTransferItem extends Model
{
    protected $fillable = ['packaging_transfer_id', 'packaging_type_id', 'qty_good'];

    public function packagingType()
    {
        return $this->belongsTo(PackagingType::class);
    }
}
