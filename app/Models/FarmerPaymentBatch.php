<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarmerPaymentBatch extends Model
{
    protected $fillable = [
        'month', 'year', 'date_paid', 'reference', 'payment_term_id', 'route_id',
        'total_farmers', 'processed_count', 'failed_count',
        'total_gross', 'total_deductions', 'total_net',
        'status', 'error_message', 'created_by', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'date_paid'    => 'date',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function progressPercent(): float
    {
        if ($this->total_farmers === 0) return 0;
        return round($this->processed_count / $this->total_farmers * 100, 1);
    }
}
