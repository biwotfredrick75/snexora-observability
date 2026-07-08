<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLetter extends Model
{
    protected $fillable = [
        'employee_id', 'template_id', 'title', 'body', 'issued_by', 'issued_at',
    ];

    protected $casts = ['issued_at' => 'datetime'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrLetterTemplate::class, 'template_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'issued_by');
    }
}
