<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Farmer extends Model
{
    protected $fillable = [
        'farmer_no', 'full_name', 'short_name', 'address', 'kra_pin', 'id_no',
        'currency', 'payment_terms_id', 'status', 'phone', 'email',
        'bank_id', 'account_no', 'gender', 'dob', 'route_id', 'village',
        'county', 'sub_county', 'location_area', 'sub_location', 'nearest_town',
        'spouse_name', 'spouse_phone', 'spouse_account_no',
        'next_of_kin_name', 'next_of_kin_account_no',
        'member_no', 'related_member_nos', 'director_name',
    ];

    protected $casts = [
        'dob' => 'date',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(FarmerContact::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(FarmerBank::class, 'bank_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(MilkRoute::class, 'route_id');
    }
}
