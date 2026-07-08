<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Bom;
use App\Models\BomItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bom::with(['items', 'mfgType:id,code,name']);

        if ($request->filled('product_code')) {
            $query->where('product_code', $request->product_code);
        }
        if ($request->filled('active_only')) {
            $query->where('is_active', true);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn ($b) => $b
                ->where('bom_no', 'like', "%$q%")
                ->orWhere('product_code', 'like', "%$q%")
                ->orWhere('description', 'like', "%$q%")
            );
        }

        $boms = $query->orderByDesc('id')->get();
        return ApiResponse::success($boms, 'BOMs retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $bom = Bom::with('items')->findOrFail($id);

        // Attach current unit costs from items master
        foreach ($bom->items as $line) {
            $item = DB::table('items')->where('stock_id', $line->component_code)->first();
            $line->unit_cost = (float) ($item?->purchase_cost ?? 0);
            $line->line_total = round($line->qty_required * $line->unit_cost, 4);
        }

        $bom->total_material_cost = $bom->items->sum('line_total');
        return ApiResponse::success($bom, 'BOM retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_code'       => 'required|string|max:20|exists:items,stock_id',
            'description'        => 'nullable|string|max:200',
            'version'            => 'nullable|string|max:20',
            'standard_batch_qty' => 'required|numeric|min:0.0001',
            'batch_unit'         => 'nullable|string|max:20',
            'mfg_type_id'        => 'nullable|integer|exists:manufacturing_types,id',
            'is_active'          => 'boolean',
            'items'              => 'required|array|min:1',
            'items.*.component_code' => 'required|string|max:20|exists:items,stock_id',
            'items.*.description'    => 'nullable|string|max:200',
            'items.*.qty_required'   => 'required|numeric|min:0.0001',
            'items.*.unit'           => 'nullable|string|max:30',
            'items.*.waste_pct'      => 'nullable|numeric|min:0|max:100',
        ]);

        $bom = DB::transaction(function () use ($data, $request) {
            // Auto-generate BOM number
            $max = Bom::lockForUpdate()->max('id') ?? 0;
            $bomNo = 'BOM-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);

            $bom = Bom::create([
                'bom_no'             => $bomNo,
                'product_code'       => $data['product_code'],
                'description'        => $data['description'] ?? '',
                'version'            => $data['version'] ?? '1.0',
                'standard_batch_qty' => $data['standard_batch_qty'],
                'batch_unit'         => $data['batch_unit'] ?? '',
                'mfg_type_id'        => $data['mfg_type_id'] ?? null,
                'is_active'          => $data['is_active'] ?? true,
                'created_by'         => auth()->user()?->user_id ?? '',
            ]);

            // Update BOM number with actual ID
            $bom->update(['bom_no' => 'BOM-' . str_pad($bom->id, 5, '0', STR_PAD_LEFT)]);

            foreach ($data['items'] as $idx => $line) {
                BomItem::create([
                    'bom_id'         => $bom->id,
                    'component_code' => $line['component_code'],
                    'description'    => $line['description'] ?? '',
                    'qty_required'   => $line['qty_required'],
                    'unit'           => $line['unit'] ?? '',
                    'waste_pct'      => $line['waste_pct'] ?? 0,
                    'sort_order'     => $idx + 1,
                ]);
            }

            return $bom->fresh(['items']);
        });

        return ApiResponse::created($bom, 'BOM created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);

        $data = $request->validate([
            'product_code'       => "required|string|max:20|exists:items,stock_id",
            'description'        => 'nullable|string|max:200',
            'version'            => 'nullable|string|max:20',
            'standard_batch_qty' => 'required|numeric|min:0.0001',
            'batch_unit'         => 'nullable|string|max:20',
            'mfg_type_id'        => 'nullable|integer|exists:manufacturing_types,id',
            'is_active'          => 'boolean',
            'items'              => 'required|array|min:1',
            'items.*.component_code' => 'required|string|max:20|exists:items,stock_id',
            'items.*.description'    => 'nullable|string|max:200',
            'items.*.qty_required'   => 'required|numeric|min:0.0001',
            'items.*.unit'           => 'nullable|string|max:30',
            'items.*.waste_pct'      => 'nullable|numeric|min:0|max:100',
        ]);

        DB::transaction(function () use ($bom, $data) {
            $bom->update([
                'product_code'       => $data['product_code'],
                'description'        => $data['description'] ?? '',
                'version'            => $data['version'] ?? '1.0',
                'standard_batch_qty' => $data['standard_batch_qty'],
                'batch_unit'         => $data['batch_unit'] ?? '',
                'mfg_type_id'        => $data['mfg_type_id'] ?? null,
                'is_active'          => $data['is_active'] ?? true,
            ]);

            $bom->items()->delete();
            foreach ($data['items'] as $idx => $line) {
                BomItem::create([
                    'bom_id'         => $bom->id,
                    'component_code' => $line['component_code'],
                    'description'    => $line['description'] ?? '',
                    'qty_required'   => $line['qty_required'],
                    'unit'           => $line['unit'] ?? '',
                    'waste_pct'      => $line['waste_pct'] ?? 0,
                    'sort_order'     => $idx + 1,
                ]);
            }
        });

        return ApiResponse::updated($bom->fresh(['items']), 'BOM updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $bom = Bom::findOrFail($id);
        if ($bom->workOrders()->exists()) {
            return ApiResponse::error('Cannot delete — work orders reference this BOM.', 422);
        }
        $bom->delete();
        return ApiResponse::deleted('BOM deleted');
    }

    /** POST /boms/{id}/clone — copy an existing BOM as a starting point for a new one */
    public function clone(int $id): JsonResponse
    {
        $source = Bom::with('items')->findOrFail($id);

        $bom = DB::transaction(function () use ($source) {
            $max   = Bom::lockForUpdate()->max('id') ?? 0;
            $bomNo = 'BOM-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);

            $bom = Bom::create([
                'bom_no'             => $bomNo,
                'product_code'       => $source->product_code,
                'description'        => $source->description . ' (copy)',
                'version'            => $source->version . '-copy',
                'standard_batch_qty' => $source->standard_batch_qty,
                'batch_unit'         => $source->batch_unit,
                'is_active'          => false,  // start inactive so user can review
                'created_by'         => auth()->user()?->user_id ?? '',
            ]);

            $bom->update(['bom_no' => 'BOM-' . str_pad($bom->id, 5, '0', STR_PAD_LEFT)]);

            foreach ($source->items as $idx => $line) {
                BomItem::create([
                    'bom_id'         => $bom->id,
                    'component_code' => $line->component_code,
                    'description'    => $line->description,
                    'qty_required'   => $line->qty_required,
                    'unit'           => $line->unit,
                    'waste_pct'      => $line->waste_pct,
                    'sort_order'     => $idx + 1,
                ]);
            }

            return $bom->fresh(['items']);
        });

        return ApiResponse::created($bom, "BOM cloned as {$bom->bom_no}");
    }

    /** Items search for BOM line autocomplete */
    public function itemsSearch(Request $request): JsonResponse
    {
        $q    = $request->get('q', '');
        $query = DB::table('items')
            ->where('inactive', 0)
            ->where(fn ($qb) => $qb
                ->where('stock_id', 'like', "%$q%")
                ->orWhere('description', 'like', "%$q%")
            );

        // When searching for a BOM's finished product, exclude raw/bought items (B flag)
        // Allow M (manufactured), D (description/assembled), F (fabricated/kit)
        $manufacturedOnly = $request->boolean('manufactured');
        if ($manufacturedOnly) {
            $query->whereIn('mb_flag', ['M', 'D', 'F']);
        }

        // The manufactured-only list backs a full dropdown (not a live-typeahead),
        // so it needs the whole set rather than the typeahead's 30-row cap.
        $items = $query->limit($manufacturedOnly ? 500 : 30)
            ->get(['stock_id', 'description', 'units', 'purchase_cost', 'mb_flag']);

        return ApiResponse::success($items, 'Items retrieved');
    }
}
