<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxGroup extends Model
{
    protected $fillable = ['description', 'inactive'];

    protected $casts = ['inactive' => 'boolean'];

    public function taxTypes()
    {
        return $this->belongsToMany(TaxType::class, 'tax_group_types', 'tax_group_id', 'tax_type_id')
                    ->withPivot('shipping');
    }
}
