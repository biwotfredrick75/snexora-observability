<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryRevision extends Model
{
    protected $fillable = [
        'employee_id', 'previous_salary', 'new_salary', 'effective_date', 'reason', 'created_by',
    ];

    protected $casts = [
        'previous_salary' => 'decimal:2',
        'new_salary'      => 'decimal:2',
        'effective_date'  => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
