<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouteGraderTransferController extends Controller
{
    // ── Dropdown data ────────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'graders' => DB::table('inventory_locations')
                ->where('inactive', false)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'routes' => DB::table('milk_routes')
                ->orderBy('route_name')
                ->get(['id', 'route_code', 'route_name']),
            'shifts' => DB::table('milk_collection_shifts')
                ->where('active', true)
                ->orderBy('description')
                ->get(['id', 'description']),
        ], 'Form data retrieved');
    }

    // ── Search milk purchase items by grader/date range ──────────────────────
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'grader_id'  => 'nullable|integer|exists:inventory_locations,id',
            'route_id'   => 'nullable|integer|exists:milk_routes,id',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $rows = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp',             'mp.id',  '=', 'mpi.purchase_id')
            ->leftJoin('farmers as f',                 'f.id',   '=', 'mpi.farmer_id')
            ->leftJoin('milk_routes as mr',            'mr.id',  '=', 'mp.route_id')
            ->leftJoin('milk_collection_shifts as sh', 'sh.id',  '=', 'mp.shift_id')
            ->leftJoin('inventory_locations as il',    'il.id',  '=', 'mp.grader_id')
            ->whereDate('mp.invoice_date', '>=', $request->start_date)
            ->whereDate('mp.invoice_date', '<=', $request->end_date)
            ->when($request->filled('grader_id'), fn ($q) => $q->where('mp.grader_id', $request->grader_id))
            ->when($request->filled('route_id'),  fn ($q) => $q->where('mp.route_id',  $request->route_id))
            ->whereIn('mp.status', ['submitted', 'approved'])
            ->select([
                'mpi.id            as item_id',
                'mp.id             as purchase_id',
                'mp.reference_no   as trans_no',
                'mp.invoice_date   as date',
                'mp.status',
                'f.farmer_no       as mem_no',
                'f.full_name       as mem_name',
                'il.name           as grader_name',
                'il.id             as grader_id',
                'mr.route_name     as route_name',
                'mr.id             as route_id',
                'sh.description    as shift',
                'mpi.quantity      as quantity',
                'mpi.unit_price    as unit_price',
                'mpi.total_price   as total_price',
            ])
            ->orderBy('mp.invoice_date')
            ->orderBy('mp.id')
            ->orderBy('f.full_name')
            ->limit(500)
            ->get();

        return ApiResponse::success([
            'rows'      => $rows,
            'total_qty' => $rows->sum('quantity'),
        ], count($rows) . ' record(s) found');
    }

    // ── Reassign selected purchase batches to new grader/route ───────────────
    public function process(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purchase_ids'   => 'required|array|min:1',
            'purchase_ids.*' => 'integer|exists:milk_purchases,id',
            'to_grader_id'   => 'nullable|integer|exists:inventory_locations,id',
            'to_route_id'    => 'nullable|integer|exists:milk_routes,id',
        ]);

        if (empty($data['to_grader_id']) && empty($data['to_route_id'])) {
            return ApiResponse::validationError(['destination' => 'Provide at least a grader or route to transfer to.']);
        }

        $user = auth()->user()?->user_id ?? 'system';

        DB::beginTransaction();
        try {
            $updates = [];
            if (! empty($data['to_grader_id'])) $updates['grader_id'] = $data['to_grader_id'];
            if (! empty($data['to_route_id']))   $updates['route_id']  = $data['to_route_id'];

            $count = DB::table('milk_purchases')
                ->whereIn('id', $data['purchase_ids'])
                ->update(array_merge($updates, [
                    'updated_at' => now(),
                ]));

            DB::commit();

            return ApiResponse::success([
                'updated' => $count,
            ], "{$count} batch(es) transferred successfully");

        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::validationError(['error' => $e->getMessage()]);
        }
    }
}
