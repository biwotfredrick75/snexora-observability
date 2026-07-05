<?php

namespace App\Http\Controllers\CasualWorkers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CasualWorker;
use App\Models\CasualWorkerTrade;
use App\Models\TransactionReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasualWorkerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CasualWorker::with('trade')->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('trade_id')) {
            $query->where('trade_id', $request->trade_id);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%$q%")
                   ->orWhere('worker_no', 'like', "%$q%")
                   ->orWhere('national_id', 'like', "%$q%")
                   ->orWhere('phone', 'like', "%$q%");
            });
        }

        return ApiResponse::success($query->get(), 'Workers retrieved');
    }

    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'trades'   => CasualWorkerTrade::orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => ['active', 'inactive', 'blacklisted'],
        ], 'Form data retrieved');
    }

    public function nextRef(): JsonResponse
    {
        $ref = $this->generateWorkerNo();
        return ApiResponse::success(['ref' => $ref], 'Next reference generated');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'national_id'       => 'nullable|string|max:20',
            'phone'             => 'nullable|string|max:20',
            'emergency_contact' => 'nullable|string|max:150',
            'emergency_phone'   => 'nullable|string|max:20',
            'trade_id'          => 'nullable|integer|exists:casual_worker_trades,id',
            'bank_name'         => 'nullable|string|max:100',
            'account_no'        => 'nullable|string|max:50',
            'mpesa_no'          => 'nullable|string|max:20',
            'status'            => 'nullable|in:active,inactive,blacklisted',
            'notes'             => 'nullable|string',
        ]);

        $data['worker_no'] = $this->generateWorkerNo();
        $data['status']    = $data['status'] ?? 'active';

        $worker = CasualWorker::create($data);
        return ApiResponse::created($worker->load('trade'), 'Worker registered');
    }

    public function show(int $id): JsonResponse
    {
        $worker = CasualWorker::with(['trade', 'payRates.trade'])->findOrFail($id);
        return ApiResponse::success($worker, 'Worker retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $worker = CasualWorker::findOrFail($id);

        $data = $request->validate([
            'name'              => 'required|string|max:150',
            'national_id'       => 'nullable|string|max:20',
            'phone'             => 'nullable|string|max:20',
            'emergency_contact' => 'nullable|string|max:150',
            'emergency_phone'   => 'nullable|string|max:20',
            'trade_id'          => 'nullable|integer|exists:casual_worker_trades,id',
            'bank_name'         => 'nullable|string|max:100',
            'account_no'        => 'nullable|string|max:50',
            'mpesa_no'          => 'nullable|string|max:20',
            'status'            => 'nullable|in:active,inactive,blacklisted',
            'notes'             => 'nullable|string',
        ]);

        $worker->update($data);
        return ApiResponse::updated($worker->load('trade'), 'Worker updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $worker = CasualWorker::findOrFail($id);
        $worker->delete();
        return ApiResponse::deleted('Worker deleted');
    }

    public function kpis(): JsonResponse
    {
        $active    = CasualWorker::where('status', 'active')->count();
        $total     = CasualWorker::count();
        $todayAtt  = \App\Models\CasualWorkerAttendance::where('work_date', today())->where('status', '!=', 'absent')->count();
        $pendingPay = \App\Models\CasualWorkerPayRun::whereIn('status', ['generated'])->count();

        return ApiResponse::success([
            'active_workers'    => $active,
            'total_workers'     => $total,
            'present_today'     => $todayAtt,
            'pending_pay_runs'  => $pendingPay,
        ], 'KPIs retrieved');
    }

    private function generateWorkerNo(): string
    {
        $last = CasualWorker::orderByDesc('id')->value('worker_no');
        if (!$last) return 'CW-001';

        preg_match('/(\d+)$/', $last, $m);
        $next = isset($m[1]) ? (int)$m[1] + 1 : 1;
        return 'CW-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }
}
