<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class ActivityLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'user_name', 'action', 'subject_type', 'subject_id',
        'description', 'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Record one activity-log entry. Actor is resolved from whichever guard
     * is currently authenticated (api or web) — pass $actor explicitly for
     * actions where auth() hasn't been established yet (e.g. a failed login).
     */
    public static function record(
        string $action,
        string $description,
        ?object $subject = null,
        array $old = [],
        array $new = [],
        ?object $actor = null,
    ): self {
        $user = $actor ?? Auth::guard('api')->user() ?? Auth::guard('web')->user();

        return self::create([
            'user_id'      => $user?->id,
            'user_name'    => $user?->real_name ?? $user?->name ?? $user?->user_id,
            'action'       => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id'   => $subject?->id ?? $subject?->getKey(),
            'description'  => $description,
            'old_values'   => $old ?: null,
            'new_values'   => $new ?: null,
            'ip_address'   => Request::ip(),
            'user_agent'   => Request::userAgent() ? substr(Request::userAgent(), 0, 255) : null,
        ]);
    }
}
