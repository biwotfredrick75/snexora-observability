<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class HrmKpiController extends Controller
{
    /**
     * Lightweight KPI summary for HrmView.vue's "My Dashboard" / "Team
     * Overview" tiles — backed by real employees data. "Present Today" and
     * "Appraisals Due" have no dedicated attendance/appraisal tables yet
     * (those modules are out of scope — see HrmView.vue's svc-placeholder
     * branch), so they're reported as 0 rather than fabricated.
     */
    public function index(): JsonResponse
    {
        $totalEmployees = Employee::where('status', '!=', 'inactive')->count();
        $onLeave = Employee::where('status', 'on_leave')->count();

        return ApiResponse::success([
            'total_employees' => $totalEmployees,
            'present_today'   => 0,
            'on_leave'        => $onLeave,
            'appraisals_due'  => 0,
        ], 'HRM KPIs retrieved');
    }
}
