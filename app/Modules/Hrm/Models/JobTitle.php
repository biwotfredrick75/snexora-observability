<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTitle extends Model
{
    protected $table = 'hrm_job_titles';

    protected $fillable = [
        'code', 'name', 'description', 'inactive',
    ];

    protected $casts = [
        'inactive' => 'boolean',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'job_title_id');
    }
}
