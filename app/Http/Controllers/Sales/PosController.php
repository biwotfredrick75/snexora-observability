<?php

namespace App\Http\Controllers\Sales;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GldTransaction;
use App\Models\Item;
use App\Models\ItemSalesPrice;
use App\Models\PosTransaction;
use App\Models\PosTransactionItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosController extends Controller
{
    // ── Resolve price for a stock_id given an optional sales_type_id ─────────
    private function resolvePrice(string $stockId, ?int $salesTypeId): float
    {
        $query = ItemSalesPrice::where('stock_id', $stockId);

        if ($salesTypeId) {
            $typeName = DB::table('sales_types')->where('id', $salesTypeId)->value('type_name');
            if ($typeName) {
                // Try exact match first; fall back to first available
                $matched = (clone $query)->where('sales_type', $typeName)->orderBy('id')->value('price');
                if ($matched !== null) {
                    return (float) $matched;
                }
            }
        }

        return (float) ($query->orderBy('id')->value('price') ?? 0);
    }

    // ── Item lookup by barcode or stock_id ────────────────────────────────────
    public function lookup(Request $request): JsonResponse
    {
        $q           = trim($request->get('q', ''));
        $locCode     = trim($request->get('loc_code', ''));
        $salesTypeId = $request->get('sales_type_id') ? (int) $request->get('sales_type_id') : null;

        if ($q === '') {
            return ApiResponse::error('Query is required', 400);
        }

        $item = Item::where('inactive', false)
            ->where('no_sale', false)
            ->where(function ($query) use ($q) {
                $query->where('stock_id', $q)
                      ->orWhere('bar_code', $q)
                      ->orWhere('description', 'like', "%{$q}%");
            })
            ->select(['stock_id', 'description', 'units', 'bar_code', 'tax_type_id',
                      'purchase_cost', 'material_cost', 'labour_cost', 'overhead_cost'])
            ->first();

        if (! $item) {
            return ApiResponse::notFound('Item not found');
        }

        $price         = $this->resolvePrice($item->stock_id, $salesTypeId);
        $standardCost  = (float) (($item->purchase_cost ?? 0) + ($item->material_cost ?? 0)
                                 + ($item->labour_cost ?? 0) + ($item->overhead_cost ?? 0));

        $qtyQuery = StockMovement::where('stock_id', $item->stock_id);
        if ($locCode !== '') {
            $qtyQuery->where('loc_code', $locCode);
        }
        $qtyOnHand = (float) ($qtyQuery->sum('qty') ?? 0);

        if ($qtyOnHand <= 0) {
            return ApiResponse::notFound('Item not found');
        }

        return ApiResponse::success([
            'stock_id'      => $item->stock_id,
            'description'   => $item->description,
            'units'         => $item->units,
            'bar_code'      => $item->bar_code,
            'price'         => $price,
            'standard_cost' => $standardCost,
            'tax_rate'      => 16.0,
            'qty_on_hand'   => $qtyOnHand,
            'loc_code'      => $locCode ?: null,
        ], 'Item found');
    }

    // ── Search items (for manual selection) ──────────────────────────────────
    public function search(Request $request): JsonResponse
    {
        $q           = trim($request->get('q', ''));
        $locCode     = trim($request->get('loc_code', ''));
        $salesTypeId = $request->get('sales_type_id') ? (int) $request->get('sales_type_id') : null;

        // Resolve the sales type name once (for price lookup)
        $salesTypeName = $salesTypeId
            ? DB::table('sales_types')->where('id', $salesTypeId)->value('type_name')
            : null;

        $query = Item::where('inactive', false)->where('no_sale', false);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('stock_id', 'like', "%{$q}%")
                    ->orWhere('bar_code', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        $itemList = $query->orderBy('description')
            ->select(['stock_id', 'description', 'units', 'bar_code',
                      'purchase_cost', 'material_cost', 'labour_cost', 'overhead_cost'])
            ->limit(300)
            ->get();

        $stockIds = $itemList->pluck('stock_id');

        // Bulk-fetch qty on hand (all movement types)
        $qtyQuery = StockMovement::whereIn('stock_id', $stockIds);
        if ($locCode !== '') {
            $qtyQuery->where('loc_code', $locCode);
        }
        $stockQtys = $qtyQuery->groupBy('stock_id')
            ->selectRaw('stock_id, SUM(qty) as qty_on_hand')
            ->pluck('qty_on_hand', 'stock_id');

        // Bulk-fetch prices: keyed by stock_id, prefer matching sales_type
        $allPrices = ItemSalesPrice::whereIn('stock_id', $stockIds)
            ->orderBy('id')
            ->get()
            ->groupBy('stock_id');

        $requireStock = filter_var($request->get('require_stock', true), FILTER_VALIDATE_BOOLEAN);

        $items = $itemList->map(function ($item) use ($stockQtys, $allPrices, $salesTypeName) {
            $priceRecords  = $allPrices->get($item->stock_id, collect());
            $matchedRecord = $salesTypeName
                ? $priceRecords->firstWhere('sales_type', $salesTypeName)
                : null;
            $price = $matchedRecord
                ? (float) $matchedRecord->price
                : (float) ($priceRecords->first()?->price ?? 0);

            $standardCost = (float) (($item->purchase_cost ?? 0) + ($item->material_cost ?? 0)
                                    + ($item->labour_cost ?? 0) + ($item->overhead_cost ?? 0));

            return [
                'stock_id'      => $item->stock_id,
                'description'   => $item->description,
                'units'         => $item->units,
                'bar_code'      => $item->bar_code,
                'price'         => $price,
                'standard_cost' => $standardCost,
                'tax_rate'      => 16.0,
                'qty_on_hand'   => (float) ($stockQtys[$item->stock_id] ?? 0),
            ];
        });

        if ($requireStock) {
            $items = $items->filter(fn ($item) => $item['qty_on_hand'] > 0);
        }

        $items = $items->values();

        return ApiResponse::success($items, 'Items retrieved');
    }

    // ── Product grid (all items with stock) ──────────────────────────────────
    public function items(Request $request): JsonResponse
    {
        $locCode    = trim($request->get('loc_code', ''));
        $categoryId = $request->get('category_id', '');
        $q          = trim($request->get('q', ''));

        $query = Item::where('inactive', false)->where('no_sale', false);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('stock_id',    'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('bar_code',    'like', "%{$q}%");
            });
        }

        if ($categoryId !== '') {
            $query->where('category_id', $categoryId);
        }

        $itemList = $query->orderBy('description')
            ->select(['stock_id', 'description', 'units', 'bar_code', 'category_id'])
            ->get();

        $stockIds = $itemList->pluck('stock_id');

        $qtyQuery = StockMovement::whereIn('stock_id', $stockIds);
        if ($locCode !== '') {
            $qtyQuery->where('loc_code', $locCode);
        }
        $stockQtys = $qtyQuery->groupBy('stock_id')
            ->selectRaw('stock_id, SUM(qty) as qty_on_hand')
            ->pluck('qty_on_hand', 'stock_id');

        $prices = ItemSalesPrice::whereIn('stock_id', $stockIds)
            ->orderBy('id')
            ->get()
            ->groupBy('stock_id')
            ->map(fn($g) => (float) ($g->first()->price ?? 0));

        $items = $itemList->map(fn($item) => [
            'stock_id'    => $item->stock_id,
            'description' => $item->description,
            'units'       => $item->units,
            'bar_code'    => $item->bar_code,
            'category_id' => $item->category_id,
            'image'       => null,
            'price'       => $prices[$item->stock_id] ?? 0.0,
            'tax_rate'    => 16.0,
            'qty_on_hand' => (float) ($stockQtys[$item->stock_id] ?? 0),
        ])->values();

        return ApiResponse::success($items, 'Items retrieved');
    }

    // ── Item categories with item counts ──────────────────────────────────────
    public function categories(): JsonResponse
    {
        $categories = DB::table('item_categories as c')
            ->leftJoin('items as i', function ($join) {
                $join->on('i.category_id', '=', 'c.id')
                     ->where('i.inactive', false)
                     ->where('i.no_sale', false);
            })
            ->selectRaw('c.id, c.name, COUNT(i.stock_id) as item_count')
            ->groupBy('c.id', 'c.name')
            ->orderBy('c.name')
            ->get();

        return ApiResponse::success($categories, 'Categories retrieved');
    }

    // ── List recent POS sales ─────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 100);
        $sales = PosTransaction::with('items')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return ApiResponse::paginated($sales, 'Sales retrieved');
    }

    // ── Show single sale (receipt) ────────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $sale = PosTransaction::with('items')->findOrFail($id);
        return ApiResponse::success($sale, 'Sale retrieved');
    }

    // ── Create a POS sale ─────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name'            => 'nullable|string|max:120',
            'debtor_no'                => 'nullable|string|max:10',
            'loc_code'                 => 'nullable|string|max:20',
            'payment_method'           => 'required|in:cash,card,mpesa',
            'amount_tendered'          => 'required|numeric|min:0',
            'mpesa_ref'                => 'nullable|string|max:60',
            'notes'                    => 'nullable|string',
            'items'                    => 'required|array|min:1',
            'items.*.stock_id'         => 'required|string',
            'items.*.description'      => 'required|string',
            'items.*.unit_price'       => 'required|numeric|min:0',
            'items.*.quantity'         => 'required|numeric|min:0.001',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_rate'         => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $subtotal  = 0;
            $taxTotal  = 0;
            $lineItems = [];

            foreach ($data['items'] as $row) {
                $disc      = $row['discount_percent'] ?? 0;
                $taxRate   = $row['tax_rate'] ?? 0;
                $gross     = round($row['unit_price'] * $row['quantity'], 4);
                $discAmt   = round($gross * $disc / 100, 4);
                $net       = $gross - $discAmt;
                $tax       = round($net * $taxRate / 100, 4);
                $lineTotal = round($net + $tax, 2);

                $subtotal += $net;
                $taxTotal += $tax;

                $lineItems[] = [
                    'stock_id'         => $row['stock_id'],
                    'description'      => $row['description'],
                    'unit_price'       => $row['unit_price'],
                    'quantity'         => $row['quantity'],
                    'discount_percent' => $disc,
                    'tax_rate'         => $taxRate,
                    'tax_amount'       => $tax,
                    'line_total'       => $lineTotal,
                ];
            }

            $total     = round($subtotal + $taxTotal, 2);
            $tendered  = round($data['amount_tendered'], 2);
            $change    = max(0, $tendered - $total);
            $today     = now()->toDateString();
            $createdBy = $request->user()?->user_id ?? 'system';
            // Resolve loc_code: use provided value, fall back to first active location code
            $locCode = $data['loc_code'] ?? null;
            if (! $locCode) {
                $locCode = DB::table('inventory_locations')
                    ->where('inactive', 0)
                    ->orderBy('id')
                    ->value('code') ?? '';
            }

            // ── 0. Stock availability check (inside transaction for consistency) ──
            $allowNegative = (bool) DB::table('gl_settings')->value('allow_negative_inventory');

            if (! $allowNegative) {
                // Aggregate requested qty per stock_id (same item may appear on multiple lines)
                $requested = [];
                foreach ($lineItems as $line) {
                    $requested[$line['stock_id']] = ($requested[$line['stock_id']] ?? 0) + $line['quantity'];
                }

                // Current balances: SUM of all movement types that affect on-hand qty
                $balanceQuery = DB::table('stock_movements')
                    ->whereIn('stock_id', array_keys($requested))
                    ->selectRaw('stock_id, SUM(qty) as balance')
                    ->groupBy('stock_id');

                if ($locCode) {
                    $balanceQuery->where('loc_code', $locCode);
                }

                $balances = $balanceQuery->pluck('balance', 'stock_id');

                $shortfalls = [];
                foreach ($requested as $stockId => $qtyNeeded) {
                    $available = (float) ($balances[$stockId] ?? 0);
                    if ($available < $qtyNeeded) {
                        // Find description for the error message
                        $desc = collect($lineItems)
                            ->firstWhere('stock_id', $stockId)['description'] ?? $stockId;
                        $shortfalls[] = "{$desc}: need {$qtyNeeded}, available " . max(0, $available);
                    }
                }

                if (! empty($shortfalls)) {
                    return ApiResponse::error(
                        'Insufficient stock: ' . implode('; ', $shortfalls),
                        422
                    );
                }
            }

            // ── 1. POS Transaction (receipt record) ───────────────────────────
            $sale = PosTransaction::create([
                'transaction_no'  => PosTransaction::nextTransactionNo(),
                'cashier_id'      => $request->user()?->user_id,
                'customer_name'   => $data['customer_name'] ?? 'Walk-in Customer',
                'subtotal'        => round($subtotal, 2),
                'tax_amount'      => round($taxTotal, 2),
                'discount_amount' => 0,
                'total_amount'    => $total,
                'payment_method'  => $data['payment_method'],
                'amount_tendered' => $tendered,
                'change_amount'   => $change,
                'mpesa_ref'       => $data['mpesa_ref'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'status'          => 'completed',
            ]);

            foreach ($lineItems as $line) {
                $sale->items()->create($line);
            }

            // ── 2. Sales Order (only when a real customer is linked) ──────────
            $debtorNo = $data['debtor_no'] ?? null;
            $so   = null;
            $soNo = $sale->transaction_no;   // fallback reference for stock/GL

            if ($debtorNo) {
                // Validate the debtor exists before attempting the insert
                $debtorExists = DB::table('customers')
                    ->where('debtor_no', $debtorNo)
                    ->exists();

                if (! $debtorExists) {
                    return ApiResponse::error(
                        "Customer '{$debtorNo}' not found. Please select a valid customer or leave blank for walk-in.",
                        422
                    );
                }

                $so = SalesOrder::create([
                    'so_no'        => 'TEMP-' . uniqid(),
                    'debtor_no'    => $debtorNo,
                    'order_date'   => $today,
                    'status'       => 'placed',
                    'sub_total'    => round($subtotal, 2),
                    'amount_total' => $total,
                    'created_by'   => $createdBy,
                ]);
                $so->update(['so_no' => SalesOrder::nextSoNo()]);
                $soNo = $so->so_no;
            }

            // ── 3. GL settings (for fallback accounts) ────────────────────────
            $glSetting = DB::table('gl_settings')->first();

            $totalRevenueDebit = 0.0;   // accumulates for the single Cash DR

            foreach ($lineItems as $line) {
                $qty = (float) $line['quantity'];

                // ── 3a. Sales Order Item (only when SO was created) ───────────
                if ($so) {
                    SalesOrderItem::create([
                        'so_id'        => $so->id,
                        'stock_id'     => $line['stock_id'],
                        'description'  => $line['description'],
                        'qty'          => $qty,
                        'price'        => $line['unit_price'],
                        'discount_pct' => $line['discount_percent'],
                        'line_total'   => $line['line_total'],
                    ]);
                }

                // ── 3b. Item master (GL accounts + standard cost) ─────────────
                $item = DB::table('items')
                    ->where('stock_id', $line['stock_id'])
                    ->select('cogs_account', 'inventory_account', 'sales_account',
                             'tax_type_id', 'dimension_id', 'dimension2_id',
                             'purchase_cost', 'material_cost', 'labour_cost', 'overhead_cost')
                    ->first();

                $standardCost = $item
                    ? (float) (($item->purchase_cost ?? 0) + ($item->material_cost ?? 0)
                               + ($item->labour_cost ?? 0) + ($item->overhead_cost ?? 0))
                    : 0.0;

                $dimId  = $item?->dimension_id  ?? null;
                $dim2Id = $item?->dimension2_id ?? null;

                // ── 3c. Stock movement: goods out (type 26, negative qty) ─────
                StockMovement::create([
                    'trans_no'      => $sale->id,
                    'stock_id'      => $line['stock_id'],
                    'type'          => StockMovement::TYPE_DELIVERY,
                    'loc_code'      => $locCode,
                    'tran_date'     => $today,
                    'date_moved'    => $today,
                    'qty'           => -$qty,
                    'price'         => $line['unit_price'],
                    'standard_cost' => $standardCost,
                    'reference'     => $soNo,
                    'comments'      => $data['notes'] ?? null,
                    'user_name'     => $createdBy,
                    'approved'      => 1,
                ]);

                // ── 3d. GL: DR COGS / CR Inventory ───────────────────────────
                $cogsAccount  = ($item?->cogs_account)      ?: ($glSetting?->items_cogs_account      ?? null);
                $invAccount   = ($item?->inventory_account) ?: ($glSetting?->items_inventory_account ?? null);

                if ($cogsAccount && $invAccount) {
                    $cogsAmt = round($standardCost * $qty, 4);
                    if ($cogsAmt != 0) {
                        GldTransaction::create([
                            'trans_no'     => $sale->id,
                            'type'         => StockMovement::TYPE_DELIVERY,
                            'tran_date'    => $today,
                            'account_code' => $cogsAccount,
                            'reference'    => $soNo,
                            'narration'    => "COGS — {$line['description']} ({$soNo})",
                            'amount'       => $cogsAmt,      // positive = debit
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);
                        GldTransaction::create([
                            'trans_no'     => $sale->id,
                            'type'         => StockMovement::TYPE_DELIVERY,
                            'tran_date'    => $today,
                            'account_code' => $invAccount,
                            'reference'    => $soNo,
                            'narration'    => "Inventory out — {$line['description']} ({$soNo})",
                            'amount'       => -$cogsAmt,     // negative = credit
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);
                    }
                }

                // ── 3e. GL: CR Sales Revenue / CR Tax Payable ─────────────────
                $salesAccount = ($item?->sales_account) ?: ($glSetting?->items_sales_account ?? null);

                if ($salesAccount) {
                    $gross        = round($line['unit_price'] * $qty, 4);
                    $discAmt      = round($gross * $line['discount_percent'] / 100, 4);
                    $netAfterDisc = $gross - $discAmt;
                    $taxAmt       = $line['tax_amount'];
                    $netRevenue   = $netAfterDisc - $taxAmt;

                    $totalRevenueDebit += $gross;

                    GldTransaction::create([
                        'trans_no'     => $sale->id,
                        'type'         => StockMovement::TYPE_DELIVERY,
                        'tran_date'    => $today,
                        'account_code' => $salesAccount,
                        'reference'    => $soNo,
                        'narration'    => "Sales — {$line['description']} ({$soNo})",
                        'amount'       => -$netRevenue,   // negative = credit
                        'created_by'   => $createdBy,
                        'dimension_id' => $dimId,
                        'dimension2_id'=> $dim2Id,
                    ]);

                    if ($taxAmt != 0 && $item?->tax_type_id) {
                        $taxAccount = DB::table('tax_types')
                            ->where('id', $item->tax_type_id)
                            ->value('sales_gl_account');

                        if ($taxAccount) {
                            GldTransaction::create([
                                'trans_no'     => $sale->id,
                                'type'         => StockMovement::TYPE_DELIVERY,
                                'tran_date'    => $today,
                                'account_code' => $taxAccount,
                                'reference'    => $soNo,
                                'narration'    => "Tax — {$line['description']} ({$soNo})",
                                'amount'       => -$taxAmt,   // negative = credit
                                'created_by'   => $createdBy,
                                'dimension_id' => $dimId,
                                'dimension2_id'=> $dim2Id,
                            ]);
                        }
                    }
                }
            }

            // ── 4. Single DR Cash/Bank entry (total of all revenue CRs) ──────
            if ($totalRevenueDebit != 0) {
                $cashAccount = match ($data['payment_method']) {
                    'cash'  => $glSetting?->pos_cash_account  ?? $glSetting?->receivable_account ?? 'CASH',
                    'card'  => $glSetting?->pos_card_account  ?? $glSetting?->receivable_account ?? 'BANK',
                    'mpesa' => $glSetting?->pos_mpesa_account ?? $glSetting?->receivable_account ?? 'MPESA',
                    default => $glSetting?->receivable_account ?? 'CASH',
                };

                GldTransaction::create([
                    'trans_no'     => $sale->id,
                    'type'         => StockMovement::TYPE_DELIVERY,
                    'tran_date'    => $today,
                    'account_code' => $cashAccount,
                    'reference'    => $soNo,
                    'narration'    => "Cash receipt — {$sale->transaction_no} ({$data['payment_method']})",
                    'amount'       => round($totalRevenueDebit, 2),  // positive = debit
                    'created_by'   => $createdBy,
                ]);
            }

            // ── 5. Sales Invoice (for visibility in the Sales module) ─────────
            $invoice = SalesInvoice::create([
                'inv_no'        => SalesInvoice::nextInvNo(),
                'pos_trans_id'  => $sale->id,
                'debtor_no'     => $debtorNo,
                'customer_name' => $data['customer_name'] ?? ($debtorNo ? null : 'Walk-in Customer'),
                'so_id'         => $so?->id,
                'invoice_date'  => $today,
                'sub_total'     => round($subtotal, 2),
                'amount_total'  => $total,
                'status'        => 'placed',
                'customer_ref'  => $sale->transaction_no,
                'comments'      => $data['notes'] ?? null,
                'created_by'    => $createdBy,
            ]);

            foreach ($lineItems as $line) {
                SalesInvoiceItem::create([
                    'inv_id'       => $invoice->id,
                    'stock_id'     => $line['stock_id'],
                    'description'  => $line['description'],
                    'qty'          => $line['quantity'],
                    'price'        => $line['unit_price'],
                    'discount_pct' => $line['discount_percent'],
                    'line_total'   => $line['line_total'],
                ]);
            }

            $msg = $so
                ? "Sale completed — Sales Order {$soNo} created"
                : 'Sale completed (walk-in — no sales order created)';

            broadcast(new DashboardEvent('direct_sale', 'created', ['ref' => $sale->receipt_no, 'amount' => $sale->total_amount]));
            return ApiResponse::created(
                array_merge($sale->load('items')->toArray(), ['so_no' => $so ? $soNo : null]),
                $msg
            );
        });
    }

    // ── Void a sale ───────────────────────────────────────────────────────────
    public function void(Request $request, int $id): JsonResponse
    {
        $sale = PosTransaction::findOrFail($id);

        if ($sale->status === 'voided') {
            return ApiResponse::error('Sale is already voided', 409);
        }

        $data = $request->validate([
            'void_reason' => 'required|string|max:200',
        ]);

        $sale->update([
            'status'      => 'voided',
            'voided_by'   => $request->user()?->user_id,
            'voided_at'   => now(),
            'void_reason' => $data['void_reason'],
        ]);

        broadcast(new DashboardEvent('direct_sale', 'voided', ['ref' => $sale->receipt_no]));
        return ApiResponse::updated($sale, 'Sale voided');
    }
}
