<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CompanyPreference;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionController extends Controller
{
    private function nextPrNo(): string
    {
        $last = PurchaseRequisition::lockForUpdate()->max('id') ?? 0;
        return 'PR' . str_pad($last + 1, 4, '0', STR_PAD_LEFT) . '/' . date('Y');
    }

    public function index(Request $request): JsonResponse
    {
        $q = PurchaseRequisition::with('items');

        if ($request->status)   $q->where('status', $request->status);
        if ($request->branch)   $q->where('branch', $request->branch);
        if ($request->from)     $q->where('requisition_date', '>=', $request->from);
        if ($request->to)       $q->where('requisition_date', '<=', $request->to);
        if ($request->pr_no)    $q->where('pr_no', 'like', "%{$request->pr_no}%");

        $prs = $q->orderByDesc('id')->limit(500)->get();
        return ApiResponse::success($prs, 'Requisitions retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'branch'           => 'required|string|max:60',
            'requisition_date' => 'required|date',
            'due_date'         => 'nullable|date',
            'items'            => 'required|array|min:1',
            'items.*.stock_id' => 'required|string|max:20',
            'items.*.qty'      => 'required|numeric|min:0.0001',
        ]);

        return DB::transaction(function () use ($request) {
            $pr = PurchaseRequisition::create([
                'pr_no'            => 'TEMP-' . uniqid(),
                'reference'        => $request->reference ?? '',
                'branch'           => $request->branch,
                'requisition_date' => $request->requisition_date,
                'due_date'         => $request->due_date,
                'status'           => 'draft',
                'raised_by'        => auth()->user()?->user_id ?? 'system',
                'comments'         => $request->comments ?? '',
                'amount'           => 0,
            ]);

            $pr->update(['pr_no' => 'PR' . str_pad($pr->id, 4, '0', STR_PAD_LEFT) . '/' . date('Y')]);

            foreach ($request->items as $item) {
                PurchaseRequisitionItem::create([
                    'pr_id'          => $pr->id,
                    'stock_id'       => $item['stock_id'],
                    'description'    => $item['description'] ?? '',
                    'qty'            => $item['qty'],
                    'unit_of_measure'=> $item['unit_of_measure'] ?? '',
                    'line_total'     => $item['line_total'] ?? 0,
                ]);
            }

            $pr->update(['amount' => $pr->items()->sum('line_total')]);
            $pr->load('items');

            return ApiResponse::created($pr, 'Purchase Requisition created');
        });
    }

    public function show(int $id): JsonResponse
    {
        $pr = PurchaseRequisition::with('items')->findOrFail($id);
        return ApiResponse::success($pr, 'Requisition retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $pr = PurchaseRequisition::findOrFail($id);

        if (!in_array($pr->status, ['draft', 'rejected'])) {
            return ApiResponse::error('Only draft or rejected requisitions can be edited', 422);
        }

        $request->validate([
            'branch'           => 'required|string|max:60',
            'requisition_date' => 'required|date',
            'items'            => 'required|array|min:1',
        ]);

        return DB::transaction(function () use ($request, $pr) {
            $pr->update([
                'reference'        => $request->reference ?? $pr->reference,
                'branch'           => $request->branch,
                'requisition_date' => $request->requisition_date,
                'due_date'         => $request->due_date,
                'comments'         => $request->comments ?? $pr->comments,
            ]);

            $pr->items()->delete();

            foreach ($request->items as $item) {
                PurchaseRequisitionItem::create([
                    'pr_id'          => $pr->id,
                    'stock_id'       => $item['stock_id'],
                    'description'    => $item['description'] ?? '',
                    'qty'            => $item['qty'],
                    'unit_of_measure'=> $item['unit_of_measure'] ?? '',
                    'line_total'     => $item['line_total'] ?? 0,
                ]);
            }

            $pr->update(['amount' => $pr->items()->sum('line_total')]);
            $pr->load('items');

            return ApiResponse::updated($pr, 'Purchase Requisition updated');
        });
    }

    public function destroy(int $id): JsonResponse
    {
        $pr = PurchaseRequisition::findOrFail($id);
        if ($pr->status !== 'draft') {
            return ApiResponse::error('Only draft requisitions can be deleted', 422);
        }
        $pr->delete();
        return ApiResponse::deleted('Purchase Requisition deleted');
    }

    // ── Approval actions ──────────────────────────────────────────────────────

    public function submit(int $id): JsonResponse
    {
        $pr = PurchaseRequisition::with('items')->findOrFail($id);
        if ($pr->status !== 'draft') return ApiResponse::error('Only draft PRs can be submitted', 422);

        $prefs = CompanyPreference::first();
        $noApprovals = !($prefs?->pr_requires_hod_approval)
                    && !($prefs?->pr_requires_finance_approval)
                    && !($prefs?->pr_requires_ceo_approval);

        if ($noApprovals) {
            $pr->update(['status' => 'ceo_approved']);
            return ApiResponse::success($pr->fresh('items'), 'Requisition approved — no approvals configured');
        }

        $pr->update(['status' => 'submitted']);
        return ApiResponse::success($pr, 'Requisition submitted for approval');
    }

    public function hodApprove(int $id): JsonResponse
    {
        $pr = PurchaseRequisition::findOrFail($id);
        if ($pr->status !== 'submitted') return ApiResponse::error('PR must be submitted first', 422);
        $pr->update([
            'status'           => 'hod_approved',
            'hod_approval_by'  => auth()->user()?->user_id ?? 'system',
            'hod_approval_date'=> now()->toDateString(),
        ]);
        return ApiResponse::success($pr, 'HOD approved');
    }

    public function financeApprove(int $id): JsonResponse
    {
        $pr = PurchaseRequisition::findOrFail($id);
        if ($pr->status !== 'hod_approved') return ApiResponse::error('PR must be HOD approved first', 422);
        $pr->update([
            'status'                => 'finance_approved',
            'finance_approval_by'   => auth()->user()?->user_id ?? 'system',
            'finance_approval_date' => now()->toDateString(),
        ]);
        return ApiResponse::success($pr, 'Finance approved');
    }

    public function ceoApprove(int $id): JsonResponse
    {
        $pr = PurchaseRequisition::findOrFail($id);
        if ($pr->status !== 'finance_approved') return ApiResponse::error('PR must be Finance approved first', 422);
        $pr->update([
            'status'              => 'ceo_approved',
            'ceo_approval_by'     => auth()->user()?->user_id ?? 'system',
            'ceo_approval_date'   => now()->toDateString(),
        ]);
        return ApiResponse::success($pr, 'CEO/Operations approved');
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        $pr = PurchaseRequisition::findOrFail($id);
        if (!in_array($pr->status, ['submitted', 'hod_approved', 'finance_approved'])) {
            return ApiResponse::error('PR cannot be rejected at this stage', 422);
        }
        $pr->update([
            'status'   => 'rejected',
            'comments' => $request->reason ?? $pr->comments,
        ]);
        return ApiResponse::success($pr, 'Requisition rejected');
    }
}
