<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlAccount extends Model
{

    protected $fillable = [
        'code', 'code2', 'name', 'group_id', 'tags', 'inactive',
        'payment_provider', 'mpesa_shortcode',
    ];

    protected function casts(): array
    {
        return [
            'inactive' => 'boolean',
            'tags'     => 'array',
        ];
    }

    public function group()
    {
        return $this->belongsTo(GlAccountGroup::class, 'group_id');
    }

    /** GL accounts configured as a selectable payment channel (any provider). */
    public function scopePaymentChannels($query, ?string $provider = null)
    {
        $query->whereNotNull('payment_provider')->where('inactive', false);
        if ($provider) {
            $query->where('payment_provider', $provider);
        }
        return $query;
    }
}
