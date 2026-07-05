<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'ref_no', 'period_start', 'period_end', 'status',
        'total_gross', 'total_paye', 'total_shif', 'total_nssf', 'total_housing_levy',
        'total_deductions', 'total_net', 'notes',
        'created_by', 'approved_by', 'approved_at', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'period_start'       => 'date',
        'period_end'         => 'date',
        'total_gross'        => 'decimal:2',
        'total_paye'         => 'decimal:2',
        'total_shif'         => 'decimal:2',
        'total_nssf'         => 'decimal:2',
        'total_housing_levy' => 'decimal:2',
        'total_deductions'   => 'decimal:2',
        'total_net'          => 'decimal:2',
        'approved_at'        => 'datetime',
        'posted_at'          => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(PayrollPosting::class);
    }

    public static function nextRefNo(): string
    {
        $last = static::orderByDesc('id')->value('ref_no');
        if (!$last) return 'PR-001/' . now()->year;

        preg_match('/PR-(\d+)/', $last, $m);
        $next = isset($m[1]) ? ((int) $m[1] + 1) : 1;
        return 'PR-' . str_pad($next, 3, '0', STR_PAD_LEFT) . '/' . now()->year;
    }
}
