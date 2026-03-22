<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerContact extends Model
{
    protected $fillable = [
        'debtor_no', 'assignment', 'reference',
        'full_name', 'phone', 'secondary_phone', 'fax', 'email',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'debtor_no', 'debtor_no');
    }
}
