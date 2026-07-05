<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CompanyPreference;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ItemController extends Controller
{
    // ── Quick search for dropdowns (with stock levels) ────────────────────────
    public function search(Request $request): JsonResponse
    {
        $q        = trim($request->get('q', ''));
        $locId    = $request->get('location_id');
        $allItems = (bool) $request->get('all', false); // bypass no_sale / qty filters
        $maxLimit = $allItems ? 1000 : 500;
        $limit    = min((int) $request->get('limit', 30), $maxLimit);

        $query = DB::table('items')
            ->where('inactive', false)
            ->orderBy('description');

        if (! $allItems) {
            $query->where('no_sale', false);
        }

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('description', 'like', "%{$q}%")
                   ->orWhere('stock_id',   'like', "%{$q}%");
            });
        }

        // Over-fetch 3× when qty filtering may remove rows; no over-fetch when all=1
        $fetchLimit = $allItems ? $limit : $limit * 3;
        $items = $query->limit($fetchLimit)->get([
            'stock_id', 'description', 'units', 'purchase_cost',
            'material_cost', 'labour_cost', 'overhead_cost',
            'sales_account', 'cogs_account', 'inventory_account', 'tax_type_id',
        ]);

        // Check company setting for negative inventory
        $allowNegative = (bool) DB::table('company_preferences')
            ->where('id', 1)
            ->value('allow_negative_inventory');

        // Attach stock quantity per location (or total if no location)
        $stockIds = $items->pluck('stock_id')->all();

        // Accept either numeric location_id (DB id) or loc_code (string code)
        $locCode = $request->get('loc_code');
        if ($locId) {
            $resolved = DB::table('inventory_locations')->where('id', $locId)->value('code');
            if ($resolved) $locCode = $resolved;
        }

        $stockQuery = DB::table('stock_movements')
            ->whereIn('stock_id', $stockIds)
            ->selectRaw('TRIM(stock_id) as stock_id, SUM(qty) as qty_in_stock');

        if ($locCode) {
            $stockQuery->where('loc_code', $locCode);
        }

        $stockQtys = $stockQuery->groupBy('stock_id')
            ->pluck('qty_in_stock', 'stock_id');

        // Fetch selling prices from item_sales_prices (first record per stock_id)
        $salesType = $request->get('sales_type');
        $salesPriceQuery = DB::table('item_sales_prices')
            ->whereIn('stock_id', $stockIds)
            ->orderBy('id');
        if ($salesType) {
            $salesPriceQuery->where('sales_type', $salesType);
        }
        $salesPrices = $salesPriceQuery->get(['stock_id', 'price'])
            ->groupBy('stock_id')
            ->map(fn ($g) => (float) $g->first()->price);

        $autoStdCost = (bool) optional(CompanyPreference::first())->auto_standard_cost ?? true;

        $result = $items->map(function ($item) use ($stockQtys, $salesPrices, $autoStdCost) {
            $standardCost = $autoStdCost
                ? (float) $item->purchase_cost
                + (float) $item->material_cost
                + (float) $item->labour_cost
                + (float) $item->overhead_cost
                : (float) $item->standard_cost;
            $qtyInStock   = (float) ($stockQtys[$item->stock_id] ?? 0);
            $sellingPrice = $salesPrices[$item->stock_id] ?? 0.0;
            $priceLocked  = $sellingPrice > 0;

            return [
                'stock_id'       => $item->stock_id,
                'description'    => $item->description,
                'unit'           => $item->units ?? '',
                'price'          => $sellingPrice,
                'price_locked'   => $priceLocked,
                'qty_in_stock'   => $qtyInStock,
                'uncommitted_qty'=> $qtyInStock,
                'standard_cost'  => $standardCost,
            ];
        });

        // Filter out zero/negative stock items when negative inventory is not allowed
        // Skip this filter for inventory inquiries (all=1) so all items are visible
        if (! $allowNegative && ! $allItems) {
            $result = $result->filter(fn ($i) => $i['qty_in_stock'] > 0);
        }

        return ApiResponse::success($result->values()->take($limit), 'Items search results');
    }

    public function index(Request $request): JsonResponse
    {
        $query = Item::with('category')->orderBy('description');

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('stock_id', 'like', $s)
                  ->orWhere('description', 'like', $s);
            });
        }

        if ($request->filled('status')) {
            $query->where('inactive', $request->status);
        }

        // active=1 → inactive=0 (used by mobile sales screens)
        if ($request->filled('active')) {
            $query->where('inactive', $request->active == '1' ? 0 : 1);
        }

        $perPage = min((int) ($request->get('per_page') ?? $request->get('limit') ?? 30), 500);
        return ApiResponse::paginated($query->paginate($perPage), 'Items retrieved');
    }

    public function show(string $stockId): JsonResponse
    {
        $item = Item::with('category')->findOrFail($stockId);
        return ApiResponse::success($item, 'Item retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_id'            => 'required|string|max:20|unique:items,stock_id',
            'description'         => 'required|string|max:200',
            'long_description'    => 'nullable|string',
            'category_id'         => 'nullable|integer',
            'tax_type_id'         => 'nullable|integer',
            'units'               => 'nullable|string|max:20',
            'mb_flag'             => 'nullable|string|max:1',
            'supplier_id'         => 'nullable|string|max:100',
            'dimension_id'        => 'nullable|integer',
            'dimension2_id'       => 'nullable|integer',
            'sales_account'       => 'nullable|string|max:15',
            'cogs_account'        => 'nullable|string|max:15',
            'inventory_account'   => 'nullable|string|max:15',
            'adjustment_account'  => 'nullable|string|max:15',
            'wip_account'         => 'nullable|string|max:15',
            'purchase_cost'       => 'nullable|numeric|min:0',
            'material_cost'       => 'nullable|numeric|min:0',
            'labour_cost'         => 'nullable|numeric|min:0',
            'overhead_cost'       => 'nullable|numeric|min:0',
            'standard_cost'       => 'nullable|numeric|min:0',
            'inactive'            => 'boolean',
            'no_sale'             => 'boolean',
            'no_purchase'         => 'boolean',
            'editable'            => 'boolean',
            'apply_batching'      => 'boolean',
            'ai_item'             => 'boolean',
            'treatment_item'      => 'boolean',
            'hs_code'             => 'nullable|string|max:100',
            'bar_code'            => 'nullable|string|max:100',
            'material_weight'     => 'nullable|numeric|min:0',
            'item_commission'     => 'nullable|numeric|min:0',
            'sell_in_pieces'      => 'nullable|integer|min:0',
            'sub_uoms'            => 'nullable|string|max:250',
            'depreciation_method' => 'nullable|string|max:1',
            'depreciation_rate'   => 'nullable|numeric|min:0',
            'depreciation_factor' => 'nullable|numeric|min:0',
            'depreciation_start'  => 'nullable|string|max:20',
            'depreciation_date'   => 'nullable|string|max:20',
            'fa_class_id'         => 'nullable|string|max:20',
            'image'               => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $validated['image_filename'] = $request->file('image')->store('item-images', 'public');
        }

        $item = Item::create($validated);
        return ApiResponse::created($item->load('category'), 'Item created');
    }

    public function update(Request $request, string $stockId): JsonResponse
    {
        $item = Item::findOrFail($stockId);

        $validated = $request->validate([
            'description'         => 'sometimes|string|max:200',
            'long_description'    => 'nullable|string',
            'category_id'         => 'nullable|integer',
            'tax_type_id'         => 'nullable|integer',
            'units'               => 'nullable|string|max:20',
            'mb_flag'             => 'nullable|string|max:1',
            'supplier_id'         => 'nullable|string|max:100',
            'dimension_id'        => 'nullable|integer',
            'dimension2_id'       => 'nullable|integer',
            'sales_account'       => 'nullable|string|max:15',
            'cogs_account'        => 'nullable|string|max:15',
            'inventory_account'   => 'nullable|string|max:15',
            'adjustment_account'  => 'nullable|string|max:15',
            'wip_account'         => 'nullable|string|max:15',
            'purchase_cost'       => 'nullable|numeric|min:0',
            'material_cost'       => 'nullable|numeric|min:0',
            'labour_cost'         => 'nullable|numeric|min:0',
            'overhead_cost'       => 'nullable|numeric|min:0',
            'standard_cost'       => 'nullable|numeric|min:0',
            'inactive'            => 'sometimes|boolean',
            'no_sale'             => 'sometimes|boolean',
            'no_purchase'         => 'sometimes|boolean',
            'editable'            => 'sometimes|boolean',
            'apply_batching'      => 'sometimes|boolean',
            'ai_item'             => 'sometimes|boolean',
            'treatment_item'      => 'sometimes|boolean',
            'hs_code'             => 'nullable|string|max:100',
            'bar_code'            => 'nullable|string|max:100',
            'material_weight'     => 'nullable|numeric|min:0',
            'item_commission'     => 'nullable|numeric|min:0',
            'sell_in_pieces'      => 'nullable|integer|min:0',
            'sub_uoms'            => 'nullable|string|max:250',
            'depreciation_method' => 'nullable|string|max:1',
            'depreciation_rate'   => 'nullable|numeric|min:0',
            'depreciation_factor' => 'nullable|numeric|min:0',
            'depreciation_start'  => 'nullable|string|max:20',
            'depreciation_date'   => 'nullable|string|max:20',
            'fa_class_id'         => 'nullable|string|max:20',
            'image'               => 'nullable|image|max:2048',
        ]);

        if ($request->hasFile('image')) {
            if ($item->image_filename) {
                Storage::disk('public')->delete($item->image_filename);
            }
            $validated['image_filename'] = $request->file('image')->store('item-images', 'public');
        }

        $item->fill($validated)->save();
        return ApiResponse::updated($item->fresh()->load('category'), 'Item updated');
    }

    public function destroy(string $stockId): JsonResponse
    {
        $item = Item::findOrFail($stockId);
        $item->update(['inactive' => true]);
        return ApiResponse::deleted('Item deactivated');
    }

    public function stockStatus(string $stockId): JsonResponse
    {
        $rows = DB::table('stock_movements')
            ->where('stock_id', $stockId)
            ->select('loc_code', DB::raw('SUM(qty) as soh'))
            ->groupBy('loc_code')
            ->get();

        $locationNames = DB::table('inventory_locations')
            ->pluck('name', 'code');

        $result = $rows->map(fn($r) => [
            'loc_code'      => $r->loc_code,
            'location'      => $locationNames[$r->loc_code] ?? $r->loc_code,
            'qty_on_hand'   => round((float) $r->soh, 4),
            'reorder_level' => 0,
            'demand'        => 0,
            'available'     => round((float) $r->soh, 4),
            'on_order'      => 0,
        ])->filter(fn($r) => $r['qty_on_hand'] != 0)->values();

        return ApiResponse::success($result);
    }

    // ── Item transaction history (stock movements) ────────────────────────────
    public function transactions(Request $request, string $id): JsonResponse
    {
        Item::findOrFail($id);

        $from    = $request->get('from', now()->subDays(30)->toDateString());
        $to      = $request->get('to',   now()->toDateString());
        $locCode = trim($request->get('loc_code', ''));

        $typeLabels = [
            10 => 'Sales Invoice',
            13 => 'Location Transfer',
            16 => 'Adjustment',
            17 => 'Stock Requisition',
            18 => 'Consumable Issue',
            20 => 'GRN Receipt',
            25 => 'GRN Receipt',
            26 => 'Sales Delivery',
            40 => 'WO Goods Issue',
            41 => 'WO Goods Receipt',
        ];

        // SOH before the from date (opening balance)
        $openingQry = DB::table('stock_movements')
            ->where('stock_id', $id)
            ->where('tran_date', '<', $from);
        if ($locCode) $openingQry->where('loc_code', $locCode);
        $openingBalance = (float) ($openingQry->sum('qty') ?? 0);

        // Movements within the period
        $mvQuery = DB::table('stock_movements')
            ->where('stock_id', $id)
            ->whereBetween('tran_date', [$from, $to])
            ->orderBy('tran_date')
            ->orderBy('trans_id');
        if ($locCode) $mvQuery->where('loc_code', $locCode);

        $movements = $mvQuery->get();

        // Location code → name map
        $locCodes = $movements->pluck('loc_code')->filter()->unique()->values()->toArray();
        $locNames = DB::table('inventory_locations')
            ->whereIn('code', $locCodes)
            ->pluck('name', 'code');

        $runningQty = $openingBalance;
        $rows = $movements->map(function ($m) use (&$runningQty, $typeLabels, $locNames) {
            $qty        = (float) $m->qty;
            $runningQty += $qty;
            $qtyIn      = $qty > 0 ? $qty   : 0;
            $qtyOut     = $qty < 0 ? -$qty  : 0;
            return [
                'trans_id'     => $m->trans_id,
                'trans_no'     => $m->trans_no,
                'type'         => $m->type,
                'type_label'   => $typeLabels[$m->type] ?? ('Type ' . $m->type),
                'reference'    => $m->reference,
                'loc_code'     => $m->loc_code,
                'location_name'=> $locNames[$m->loc_code] ?? $m->loc_code,
                'tran_date'    => $m->tran_date,
                'detail'       => $m->comments,
                'batch_no'     => $m->batch_no,
                'qty_in'       => $qtyIn,
                'qty_out'      => $qtyOut,
                'qty_on_hand'  => round($runningQty, 4),
                'variance_pos' => 0,
                'variance_neg' => 0,
            ];
        });

        return ApiResponse::success([
            'opening_balance' => round($openingBalance, 4),
            'from'            => $from,
            'to'              => $to,
            'rows'            => $rows,
        ], 'Transactions retrieved');
    }
}
