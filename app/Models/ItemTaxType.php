<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemTaxType extends Model
{
    protected $fillable = ['description', 'fully_exempt', 'inactive'];

    protected $casts = [
        'fully_exempt' => 'boolean',
        'inactive'     => 'boolean',
    ];

    public function exemptTypes()
    {
        return $this->belongsToMany(TaxType::class, 'item_tax_type_exemptions', 'item_tax_type_id', 'tax_type_id');
    }
}
