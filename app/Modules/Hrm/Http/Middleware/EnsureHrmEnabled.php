<?php

namespace App\Modules\Hrm\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module on/off switch.
 *
 * Blocks every HRM API route when `company_preferences.hrm_enabled`
 * is false — the module stays installed but fully dormant.
 */
class EnsureHrmEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (Schema::hasColumn('company_preferences', 'hrm_enabled')) {
            $enabled = DB::table('company_preferences')->value('hrm_enabled');

            // null → treat as enabled (fresh install before the row exists)
            if ($enabled !== null && ! $enabled) {
                return ApiResponse::forbidden('The HRM module is disabled for this company.');
            }
        }

        return $next($request);
    }
}
