<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WorkOrder;
use App\Services\Manufacturing\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkOrderStageController extends Controller
{
    /** GET /manufacturing/work-orders/{id}/stages */
    public function index(int $id)
    {
        $wo = WorkOrder::with(['bom', 'workCentre', 'mfgType'])->findOrFail($id);

        $stages = DB::table('work_order_stages')
            ->where('work_order_id', $id)
            ->orderBy('stage_order')
            ->get();

        $byproducts = DB::table('work_order_byproducts')
            ->where('work_order_id', $id)
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success([
            'work_order' => $wo,
            'stages'     => $stages,
            'byproducts' => $byproducts,
        ]);
    }

    /** POST /manufacturing/work-orders/{id}/stages/{stageCode}/complete */
    public function complete(Request $request, int $id, string $stageCode)
    {
        $wo = WorkOrder::with('mfgType')->findOrFail($id);

        if (! in_array($wo->status, [WorkOrderService::STATUS_RELEASED, WorkOrderService::STATUS_IN_PROGRESS])) {
            return ApiResponse::error('Work order must be Released or In Progress to advance stages.', 422);
        }

        $stage = DB::table('work_order_stages')
            ->where('work_order_id', $id)
            ->where('stage_code', $stageCode)
            ->where('status', 'active')
            ->first();

        if (! $stage) {
            return ApiResponse::error('Stage not found or not currently active.', 422);
        }

        $request->validate([
            'notes'                    => 'nullable|string',
            'qa_data'                  => 'nullable|array',
            'byproducts'               => 'nullable|array',
            'byproducts.*.stock_id'    => 'required_with:byproducts|string|max:50',
            'byproducts.*.qty'         => 'required_with:byproducts|numeric|min:0.0001',
            'byproducts.*.unit'        => 'nullable|string|max:30',
            'byproducts.*.location_code' => 'nullable|string|max:200',
        ]);

        $user = Auth::user()?->user_id ?? 'system';

        DB::transaction(function () use ($request, $wo, $stage, $stageCode, $id, $user) {
            // Mark current stage completed
            DB::table('work_order_stages')
                ->where('id', $stage->id)
                ->update([
                    'status'       => 'completed',
                    'notes'        => $request->notes,
                    'qa_data'      => $request->qa_data ? json_encode($request->qa_data) : null,
                    'completed_by' => $user,
                    'completed_at' => now(),
                    'updated_at'   => now(),
                ]);

            // Register by-products + stock movements
            foreach ($request->byproducts ?? [] as $bp) {
                $stockId  = $bp['stock_id'];
                $qty      = (float) $bp['qty'];
                $unit     = $bp['unit'] ?? '';
                $locCode  = $bp['location_code'] ?? $wo->output_location_code ?? $wo->location_code ?? '';
                $itemRow  = DB::table('items')->where('stock_id', $stockId)->first();
                $desc     = $itemRow?->description ?? $stockId;
                $unitCost = (float) ($itemRow?->purchase_cost ?? 0);

                $movId = DB::table('stock_movements')->insertGetId([
                    'trans_no'      => $wo->id,
                    'stock_id'      => $stockId,
                    'type'          => WorkOrderService::TYPE_WO_RECEIPT,
                    'loc_code'      => $locCode,
                    'tran_date'     => now()->toDateString(),
                    'qty'           => $qty,
                    'price'         => $unitCost,
                    'standard_cost' => $unitCost,
                    'reference'     => $wo->wo_no,
                    'comments'      => "By-product [{$stage->stage_name}] — {$wo->wo_no}",
                    'unique_key'    => Str::uuid()->toString(),
                ]);

                DB::table('work_order_byproducts')->insert([
                    'work_order_id'       => $wo->id,
                    'work_order_stage_id' => $stage->id,
                    'stage_code'          => $stageCode,
                    'stage_name'          => $stage->stage_name,
                    'stock_id'            => $stockId,
                    'description'         => $desc,
                    'qty'                 => $qty,
                    'unit'                => $unit,
                    'location_code'       => $locCode,
                    'registered_by'       => $user,
                    'stock_movement_id'   => $movId,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            // Activate the next pending stage
            $next = DB::table('work_order_stages')
                ->where('work_order_id', $id)
                ->where('stage_order', '>', $stage->stage_order)
                ->where('status', 'pending')
                ->orderBy('stage_order')
                ->first();

            if ($next) {
                DB::table('work_order_stages')
                    ->where('id', $next->id)
                    ->update([
                        'status'     => 'active',
                        'started_by' => $user,
                        'started_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            // Transition WO to in_progress if still released
            if ($wo->status === WorkOrderService::STATUS_RELEASED) {
                $wo->update(['status' => WorkOrderService::STATUS_IN_PROGRESS]);
            }
        });

        return $this->index($id);
    }
}
