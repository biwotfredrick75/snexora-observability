<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintingProfileItem extends Model
{
    protected $fillable = ['profile_id', 'report_id', 'printer'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PrintingProfile::class, 'profile_id');
    }
}
