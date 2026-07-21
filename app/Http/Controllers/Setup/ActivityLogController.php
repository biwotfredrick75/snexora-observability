<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * GET /api/setup/activity-log
     * Paginated, filterable feed of user activity (logins, CRUD, voids).
     */
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::query()->orderByDesc('id');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn ($w) => $w
                ->where('description', 'like', "%$q%")
                ->orWhere('user_name', 'like', "%$q%"));
        }

        $perPage = min((int) $request->get('per_page', 25), 100);
        $logs    = $query->paginate($perPage);

        return ApiResponse::success($logs, 'Activity log retrieved');
    }

    /** GET /api/setup/activity-log/actions — distinct action names for the filter dropdown */
    public function actions(): JsonResponse
    {
        $actions = ActivityLog::query()->distinct()->orderBy('action')->pluck('action');
        return ApiResponse::success($actions, 'Actions retrieved');
    }
}
