<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingReceiveItem extends Model
{
    protected $fillable = ['packaging_receive_id', 'packaging_type_id', 'quantity', 'condition'];

    public function packagingType()
    {
        return $this->belongsTo(PackagingType::class);
    }
}
