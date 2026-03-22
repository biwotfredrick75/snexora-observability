<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackagingReceive extends Model
{
    protected $fillable = [
        'receiving_date', 'from_location_id', 'return_to_location_id', 'comments', 'created_by',
    ];

    public function items()
    {
        return $this->hasMany(PackagingReceiveItem::class);
    }
}
