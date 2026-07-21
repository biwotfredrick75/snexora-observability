<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\Item;
use App\Models\ItemCategory;
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
            'scrap_pct'          => 'nullable|numeric|min:0|max:100',
            'target_margin_pct'  => 'nullable|numeric|min:0|max:99.99',
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
                'scrap_pct'          => $data['scrap_pct'] ?? 0,
                'target_margin_pct'  => $data['target_margin_pct'] ?? 35,
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
            'scrap_pct'          => 'nullable|numeric|min:0|max:100',
            'target_margin_pct'  => 'nullable|numeric|min:0|max:99.99',
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
                'scrap_pct'          => $data['scrap_pct'] ?? 0,
                'target_margin_pct'  => $data['target_margin_pct'] ?? 35,
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

    /**
     * POST /manufacturing/boms/import
     *
     * Bulk-creates a BOM from an uploaded ingredient sheet — a tab-separated
     * "recipe" export like INGREDIENT / FORMULAE / AMOUNT IN 1 TONNE / COST
     * PER KG / TOTAL COST (a trailing STANDARDS/costing block, if present,
     * is ignored — parsing stops at the TOTAL row). Any ingredient, or the
     * finished product itself, that doesn't already exist as an Item is
     * auto-created — matched by description first so re-importing the same
     * sheet doesn't create duplicates.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'                  => 'required|file|max:10240',
            'product_code'          => 'nullable|string|max:20',
            'product_name'          => 'nullable|string|max:200',
            'description'           => 'nullable|string|max:200',
            'version'               => 'nullable|string|max:20',
            'batch_unit'            => 'nullable|string|max:20',
            'product_category_id'   => 'nullable|integer|exists:item_categories,id',
            'component_category_id' => 'nullable|integer|exists:item_categories,id',
        ]);

        // Auto-created items need a category to derive their stock_id prefix
        // from (first 3 letters of the category name). Default to sensible,
        // generic categories rather than hardcoding anything feed-specific.
        $productCategory   = $this->resolveCategory($request->input('product_category_id'), 'Finished Goods');
        $componentCategory = $this->resolveCategory($request->input('component_category_id'), 'Raw Materials');

        $rows = $this->parseIngredientFile($request->file('file'));

        if (empty($rows)) {
            return ApiResponse::validationError(['file' => 'No ingredient rows found in the uploaded file.']);
        }

        try {
            $result = DB::transaction(function () use ($request, $rows, $productCategory, $componentCategory) {
                $itemsCreated = [];
                $itemsMatched = [];

                // Finished product — reuse if given and it already exists,
                // otherwise auto-create it (mb_flag=M — manufactured).
                $productName = $request->input('product_name')
                    ?: pathinfo($request->file('file')->getClientOriginalName(), PATHINFO_FILENAME);
                $productCode = $request->input('product_code') ?: null;
                $product = $productCode
                    ? Item::find($productCode)
                    : Item::whereRaw('LOWER(description) = ?', [mb_strtolower(trim($productName))])->first();

                if (!$product) {
                    $product = $this->createItem($productCode ?: $this->generateStockId($productCategory), $productName, 'M', 0, $productCategory->id);
                    $itemsCreated[] = $product->stock_id;
                } else {
                    $itemsMatched[] = $product->stock_id;
                }

                $lines = [];
                foreach ($rows as $row) {
                    $item = Item::whereRaw('LOWER(description) = ?', [mb_strtolower($row['name'])])->first();

                    if (!$item) {
                        $item = $this->createItem($this->generateStockId($componentCategory), $row['name'], 'B', $row['cost'], $componentCategory->id);
                        $itemsCreated[] = $item->stock_id;
                    } else {
                        $itemsMatched[] = $item->stock_id;
                        // Never clobber an existing, possibly more current, price —
                        // only fill in cost if the item doesn't already have one.
                        if ((float) $item->purchase_cost <= 0 && $row['cost'] > 0) {
                            $item->update(['purchase_cost' => $row['cost']]);
                        }
                    }

                    if ($row['qty'] > 0) {
                        $lines[] = [
                            'component_code' => $item->stock_id,
                            'description'    => $row['name'],
                            'qty_required'   => $row['qty'],
                        ];
                    }
                }

                if (empty($lines)) {
                    throw new \RuntimeException('None of the ingredient rows had a usable quantity — nothing to import.');
                }

                $batchQty = array_sum(array_column($lines, 'qty_required'));

                $max   = Bom::lockForUpdate()->max('id') ?? 0;
                $bomNo = 'BOM-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);

                $bom = Bom::create([
                    'bom_no'             => $bomNo,
                    'product_code'       => $product->stock_id,
                    'description'        => $request->input('description') ?: $productName,
                    'version'            => $request->input('version') ?: '1.0',
                    'standard_batch_qty' => $batchQty,
                    'batch_unit'         => $request->input('batch_unit') ?: 'KG',
                    'is_active'          => true,
                    'created_by'         => auth()->user()?->user_id ?? '',
                ]);
                $bom->update(['bom_no' => 'BOM-' . str_pad($bom->id, 5, '0', STR_PAD_LEFT)]);

                foreach ($lines as $idx => $line) {
                    BomItem::create([
                        'bom_id'         => $bom->id,
                        'component_code' => $line['component_code'],
                        'description'    => $line['description'],
                        'qty_required'   => $line['qty_required'],
                        'unit'           => 'KG',
                        'waste_pct'      => 0,
                        'sort_order'     => $idx + 1,
                    ]);
                }

                return [
                    'bom'           => $bom->fresh(['items']),
                    'items_created' => array_values(array_unique($itemsCreated)),
                    'items_matched' => array_values(array_unique($itemsMatched)),
                ];
            });
        } catch (\RuntimeException $e) {
            return ApiResponse::validationError(['file' => $e->getMessage()]);
        }

        $bom = $result['bom'];
        $bom->items_created = $result['items_created'];
        $bom->items_matched = $result['items_matched'];

        $summary = "{$bom->bom_no} created with " . $bom->items->count() . ' components ('
            . count($result['items_created']) . ' new item(s) created, '
            . count($result['items_matched']) . ' matched existing)';

        return ApiResponse::created($bom, $summary);
    }

    /**
     * Parses a tab-separated ingredient sheet into
     * [['name' => ..., 'qty' => float, 'cost' => float], ...].
     * Stops at the first row whose ingredient cell is empty or reads
     * TOTAL/STANDARDS (the row-total and downstream costing block aren't
     * ingredient lines).
     */
    private function parseIngredientFile($file): array
    {
        // Detect delimiter from the header line — this template is
        // tab-separated, but plain CSV exports of the same shape are common.
        $firstLine = fgets(fopen($file->getRealPath(), 'r'));
        $delimiter = substr_count($firstLine, "\t") >= substr_count($firstLine, ',') ? "\t" : ',';

        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) return [];

        // Strip a UTF-8 BOM if present.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $headers = null;
        $rows    = [];

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn ($h) => mb_strtolower(trim($h)), $line);
                continue;
            }

            $row  = array_combine($headers, array_pad($line, count($headers), ''));
            $name = trim($row[$headers[0]] ?? '');

            if ($name === '' || in_array(mb_strtoupper($name), ['TOTAL', 'STANDARDS'])) {
                break;
            }

            $qtyCol  = $this->findColumn($headers, ['amount in 1 tonne', 'amount', 'qty', 'quantity']);
            $costCol = $this->findColumn($headers, ['cost per kg', 'cost', 'unit cost', 'cost per unit']);

            $rows[] = [
                'name' => $name,
                'qty'  => $this->parseNumber($row[$qtyCol]  ?? null),
                'cost' => $this->parseNumber($row[$costCol] ?? null),
            ];
        }
        fclose($handle);

        return $rows;
    }

    private function findColumn(array $headers, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (in_array($c, $headers)) return $c;
        }
        return null;
    }

    /** "1,558.78" / " - " / "" → float, treating a bare dash as 0 (unused ingredient). */
    private function parseNumber(?string $val): float
    {
        $val = trim((string) $val, " \t\"");
        if ($val === '' || $val === '-') return 0.0;
        return (float) str_replace(',', '', $val);
    }

    /**
     * {first 3 letters of category name}{5-digit sequence}, e.g. RAW00001,
     * RAW00125 — sequence continues from whatever's already used under that
     * prefix rather than resetting, and is padded/extended if it overflows.
     */
    private function generateStockId(ItemCategory $category): string
    {
        $prefix = mb_substr(preg_replace('/[^A-Za-z]/', '', $category->name), 0, 3);
        $prefix = mb_strtoupper($prefix ?: 'GEN');

        $max = Item::where('stock_id', 'like', "{$prefix}%")
            ->get(['stock_id'])
            ->map(fn ($i) => (int) preg_replace('/\D/', '', mb_substr($i->stock_id, mb_strlen($prefix))))
            ->max() ?? 0;

        do {
            $max++;
            $code = $prefix . str_pad($max, 5, '0', STR_PAD_LEFT);
        } while (Item::whereRaw('LOWER(stock_id) = ?', [mb_strtolower($code)])->exists());

        return $code;
    }

    /** Falls back to the named category (created if missing) when no id is given/found. */
    private function resolveCategory(?int $categoryId, string $fallbackName): ItemCategory
    {
        if ($categoryId && $category = ItemCategory::find($categoryId)) {
            return $category;
        }

        return ItemCategory::firstOrCreate(['name' => $fallbackName]);
    }

    private function createItem(string $stockId, string $description, string $mbFlag, float $cost, ?int $categoryId = null): Item
    {
        return Item::create([
            'stock_id'      => $stockId,
            'category_id'   => $categoryId,
            'description'   => trim($description),
            'units'         => 'KG',
            'mb_flag'       => $mbFlag,
            'purchase_cost' => $cost,
            'standard_cost' => $cost,
        ]);
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
