<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dimension extends Model
{
    protected $fillable = [
        'reference', 'name', 'type', 'start_date',
        'date_required_by', 'tags', 'memo', 'inactive',
    ];

    protected $casts = [
        'tags'             => 'array',
        'start_date'       => 'date',
        'date_required_by' => 'date',
        'inactive'         => 'boolean',
    ];

    /**
     * Generate the next sequential reference like 001/2026.
     */
    public static function nextReference(): string
    {
        $year = now()->year;
        $last = static::where('reference', 'like', "%/{$year}")
            ->orderByDesc('id')
            ->value('reference');

        $seq = $last ? ((int) explode('/', $last)[0]) + 1 : 1;

        return str_pad($seq, 3, '0', STR_PAD_LEFT) . '/' . $year;
    }
}
