<?php

namespace App\Http\Controllers\Sales;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GldTransaction;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Traits\ValidatesSellingPrice;
use Illuminate\Support\Facades\DB;

class SalesDeliveryController extends Controller
{
    use ValidatesSellingPrice;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recalcTotals(SalesDelivery $delivery): void
    {
        $subTotal = $delivery->items()->get()->sum(function ($item) {
            return $item->qty * $item->price * (1 - $item->discount_pct / 100);
        });
        $delivery->sub_total    = round($subTotal, 2);
        $delivery->amount_total = round($subTotal + $delivery->shipping_charge, 2);
        $delivery->save();
    }

    private function calcLineTotal(array $item): float
    {
        return round(
            floatval($item['qty']) * floatval($item['price']) * (1 - floatval($item['discount_pct'] ?? 0) / 100),
            2
        );
    }

    // Qty already returned to stock against this delivery, keyed by stock_id.
    // Returns are recorded as positive type=26 movements on the delivery's own
    // trans_no (the original delivery movement itself is always negative).
    private function returnedQtysByStockId(int $deliveryId)
    {
        return DB::table('stock_movements')
            ->where('trans_no', $deliveryId)
            ->where('type', StockMovement::TYPE_DELIVERY)
            ->where('qty', '>', 0)
            ->groupBy('stock_id')
            ->selectRaw('TRIM(stock_id) as stock_id, SUM(qty) as returned_qty')
            ->pluck('returned_qty', 'stock_id');
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function nextRef(): JsonResponse
    {
        $ref = DB::transaction(fn() => SalesDelivery::nextDnNo());
        return ApiResponse::success(['ref' => $ref], 'Next reference generated');
    }

    public function index(Request $request): JsonResponse
    {
        $query = SalesDelivery::with(['customer:debtor_no,name'])->orderByDesc('id');

        if ($v = $request->get('debtor_no'))  $query->where('debtor_no', $v);
        if ($v = $request->get('date_from'))  $query->where('delivery_date', '>=', $v);
        if ($v = $request->get('date_to'))    $query->where('delivery_date', '<=', $v);
        if ($v = $request->get('status'))     $query->where('status', $v);
        if ($v = $request->get('dn_no'))      $query->where('dn_no', 'like', "%{$v}%");

        if ($request->boolean('not_invoiced', false)) {
            // Exclude only deliveries that are *fully* invoiced — a delivery with
            // some qty still left to invoice (partial invoicing) must keep showing up.
            $deliveredTotals = DB::table('sales_delivery_items')
                ->select('delivery_id', DB::raw('SUM(qty) as total_qty'))
                ->groupBy('delivery_id');

            $invoicedTotals = DB::table('sales_invoice_items as ii')
                ->join('sales_invoices as i', 'ii.inv_id', '=', 'i.id')
                ->where('i.status', 'placed')
                ->whereNotNull('i.dn_id')
                ->select('i.dn_id', DB::raw('SUM(ii.qty) as total_invoiced'))
                ->groupBy('i.dn_id');

            $fullyInvoicedIds = DB::query()
                ->fromSub($deliveredTotals, 'd')
                ->leftJoinSub($invoicedTotals, 'inv', 'inv.dn_id', '=', 'd.delivery_id')
                ->whereRaw('COALESCE(inv.total_invoiced, 0) >= d.total_qty')
                ->pluck('d.delivery_id');

            $query->where('status', 'placed')->whereNotIn('id', $fullyInvoicedIds);
        }

        $deliveries = $query->paginate(min((int) $request->get('per_page', 30), 200));

        return ApiResponse::paginated($deliveries, 'Deliveries retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'debtor_no'       => 'required|string|max:10',
            'so_id'           => 'nullable|integer|exists:sales_orders,id',
            'delivery_date'   => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'branch_id'       => 'nullable|integer',
            'invoice_before'  => 'nullable|date',
            'payment_terms'   => 'nullable|integer',
            'price_list_id'   => 'nullable|integer',
            'shipping_charge' => 'nullable|numeric|min:0',
            'dimension_id'    => 'nullable|integer',
            'dimension2_id'   => 'nullable|integer',
            'location_id'     => 'nullable|integer',
            'vehicle'         => 'nullable|string|max:50',
            'shift'           => 'nullable|string|max:20',
            'deliver_to'      => 'nullable|string|max:100',
            'address'         => 'nullable|string',
            'contact_phone'   => 'nullable|string|max:30',
            'customer_ref'    => 'nullable|string|max:60',
            'comments'        => 'nullable|string',
            'shipping_company_id' => 'nullable|integer',
            'items'                => 'required|array|min:1',
            'items.*.stock_id'     => 'required|string|max:20',
            'items.*.description'  => 'required|string|max:200',
            'items.*.qty'          => 'required|numeric|min:0.0001',
            'items.*.price'        => 'required|numeric|min:0',
            'items.*.discount_pct' => 'nullable|numeric|min:0|max:100',
            'items.*.unit'         => 'nullable|string|max:20',
            'items.*.standard_cost'=> 'nullable|numeric|min:0',
        ]);

        $priceErrors = $this->checkItemPrices($validated['items']);
        if (! empty($priceErrors)) {
            return ApiResponse::validationError($priceErrors);
        }

        return DB::transaction(function () use ($validated) {
            $delivery = SalesDelivery::create([
                'dn_no'              => 'TEMP-' . uniqid(),
                'so_id'              => $validated['so_id'] ?? null,
                'debtor_no'          => $validated['debtor_no'],
                'branch_id'          => $validated['branch_id'] ?? null,
                'delivery_date'      => $validated['delivery_date'],
                'invoice_before'     => $validated['invoice_before'] ?? null,
                'payment_terms'      => $validated['payment_terms'] ?? null,
                'price_list_id'      => $validated['price_list_id'] ?? null,
                'shipping_charge'    => $validated['shipping_charge'] ?? 0,
                'dimension_id'       => $validated['dimension_id'] ?? null,
                'dimension2_id'      => $validated['dimension2_id'] ?? null,
                'location_id'        => $validated['location_id'] ?? null,
                'vehicle'            => $validated['vehicle'] ?? null,
                'shift'              => $validated['shift'] ?? null,
                'deliver_to'         => $validated['deliver_to'] ?? null,
                'address'            => $validated['address'] ?? null,
                'contact_phone'      => $validated['contact_phone'] ?? null,
                'customer_ref'       => $validated['customer_ref'] ?? null,
                'comments'           => $validated['comments'] ?? null,
                'shipping_company_id'=> $validated['shipping_company_id'] ?? null,
                'status'             => 'draft',
                'created_by'         => auth()->user()?->user_id ?? 'system',
            ]);

            $delivery->update(['dn_no' => SalesDelivery::nextDnNo()]);

            $subTotal = 0;
            foreach ($validated['items'] as $itemData) {
                $lineTotal = $this->calcLineTotal($itemData);
                $subTotal += $lineTotal;
                SalesDeliveryItem::create([
                    'delivery_id'   => $delivery->id,
                    'stock_id'      => $itemData['stock_id'],
                    'description'   => $itemData['description'],
                    'qty'           => $itemData['qty'],
                    'unit'          => $itemData['unit'] ?? null,
                    'price'         => $itemData['price'],
                    'standard_cost' => $itemData['standard_cost'] ?? 0,
                    'discount_pct'  => $itemData['discount_pct'] ?? 0,
                    'line_total'    => $lineTotal,
                ]);
            }

            $delivery->sub_total    = round($subTotal, 2);
            $delivery->amount_total = round($subTotal + ($validated['shipping_charge'] ?? 0), 2);
            $delivery->save();

            try {
                broadcast(new DashboardEvent('delivery', 'created', ['ref' => $delivery->dn_no, 'amount' => $delivery->amount_total]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }
            return ApiResponse::created($delivery->load('items'), 'Sales delivery created');
        });
    }

    public function show(int $id): JsonResponse
    {
        $delivery = SalesDelivery::with(['items', 'customer'])->find($id);
        if (! $delivery) {
            return ApiResponse::notFound('Sales delivery not found');
        }
        return ApiResponse::success($delivery, 'Sales delivery retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $delivery = SalesDelivery::find($id);
        if (! $delivery) {
            return ApiResponse::notFound('Sales delivery not found');
        }

        $validated = $request->validate([
            'delivery_date'   => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'invoice_before'  => 'nullable|date',
            'payment_terms'   => 'nullable|integer',
            'price_list_id'   => 'nullable|integer',
            'shipping_charge' => 'nullable|numeric|min:0',
            'dimension_id'    => 'nullable|integer',
            'dimension2_id'   => 'nullable|integer',
            'location_id'     => 'nullable|integer',
            'vehicle'         => 'nullable|string|max:50',
            'shift'           => 'nullable|string|max:20',
            'deliver_to'      => 'nullable|string|max:100',
            'address'         => 'nullable|string',
            'contact_phone'   => 'nullable|string|max:30',
            'customer_ref'    => 'nullable|string|max:60',
            'comments'        => 'nullable|string',
            'shipping_company_id' => 'nullable|integer',
            'branch_id'       => 'nullable|integer',
        ]);

        $delivery->update($validated);
        $this->recalcTotals($delivery);

        return ApiResponse::updated($delivery->load('items'), 'Sales delivery updated');
    }

    public function place(int $id): JsonResponse
    {
        $delivery = SalesDelivery::with('items')->find($id);
        if (! $delivery) {
            return ApiResponse::notFound('Sales delivery not found');
        }
        if ($delivery->status !== 'draft') {
            return ApiResponse::error('Delivery is not in draft status', 422);
        }

        $company   = DB::table('company_preferences')->first();
        $glSetting = DB::table('gl_settings')->first();

        return DB::transaction(function () use ($delivery, $company, $glSetting) {
            $delivery->update(['status' => 'placed']);

            $createdBy     = auth()->user()?->user_id ?? 'system';
            $dnNo          = $delivery->dn_no;
            $tranDate      = $delivery->delivery_date?->toDateString() ?? now()->toDateString();
            $totalGrossSales = 0.0;
            $totalDiscounts  = 0.0;
            $totalTax        = 0.0;

            // Resolve stock location code for movements; fall back to first active location
            $locCode = $delivery->location_id
                ? DB::table('inventory_locations')->where('id', $delivery->location_id)->value('code')
                : DB::table('inventory_locations')->where('inactive', 0)->value('code');

            foreach ($delivery->items as $dnItem) {
                $item = DB::table('items')->where('stock_id', $dnItem->stock_id)
                    ->select('cogs_account', 'inventory_account', 'sales_account',
                             'tax_type_id', 'dimension_id', 'dimension2_id', 'mb_flag')
                    ->first();

                if (! $item) continue;

                $qty          = (float) $dnItem->qty;
                $standardCost = (float) ($dnItem->standard_cost ?? 0);
                if ($standardCost == 0) {
                    $standardCost = (float) (DB::table('items')
                        ->where('stock_id', $dnItem->stock_id)
                        ->selectRaw('COALESCE(purchase_cost,0)+COALESCE(material_cost,0)+COALESCE(labour_cost,0)+COALESCE(overhead_cost,0) as c')
                        ->value('c') ?? 0);
                }

                $dimId  = ($item->dimension_id  ?? null) ?: $delivery->dimension_id;
                $dim2Id = ($item->dimension2_id ?? null) ?: $delivery->dimension2_id;

                // ── Stock movement: goods out (negative qty) ───────────────────
                // Service items (mb_flag = 'S') aren't stock-controlled — they
                // have no location, so skip the physical movement entirely.
                if ($item->mb_flag !== 'S') {
                    StockMovement::create([
                        'trans_no'      => $delivery->id,
                        'stock_id'      => $dnItem->stock_id,
                        'type'          => StockMovement::TYPE_DELIVERY,
                        'loc_code'      => $locCode,
                        'tran_date'     => $tranDate,
                        'date_moved'    => $tranDate,
                        'qty'           => -$qty,
                        'price'         => $dnItem->price,
                        'standard_cost' => $standardCost,
                        'reference'     => $dnNo,
                        'comments'      => $delivery->comments,
                        'user_name'     => $createdBy,
                        'vehicle'       => $delivery->vehicle ?? '',
                        'shift'         => $delivery->shift   ?? '',
                        'approved'      => 1,
                    ]);
                }

                // ── COGS / Inventory ──────────────────────────────────────────
                if ($item->cogs_account && $item->inventory_account) {
                    $cogsAmount = round($standardCost * $qty, 4);
                    if ($cogsAmount != 0) {
                        GldTransaction::create([
                            'trans_no'     => $delivery->id,
                            'type'         => StockMovement::TYPE_DELIVERY,
                            'tran_date'    => $tranDate,
                            'account_code' => $item->cogs_account,
                            'reference'    => $dnNo,
                            'narration'    => "COGS — {$dnItem->description} ({$dnNo})",
                            'amount'       => $cogsAmount,
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);
                        GldTransaction::create([
                            'trans_no'     => $delivery->id,
                            'type'         => StockMovement::TYPE_DELIVERY,
                            'tran_date'    => $tranDate,
                            'account_code' => $item->inventory_account,
                            'reference'    => $dnNo,
                            'narration'    => "Inventory out — {$dnItem->description} ({$dnNo})",
                            'amount'       => -$cogsAmount,
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);
                    }
                }

                // ── Revenue: Sales / Tax / Discount ───────────────────────────
                $salesAccount = ($item->sales_account ?: null)
                    ?: ($glSetting->items_sales_account ?: null)
                    ?: ($glSetting->sales_account ?: null)
                    ?: 'SALES_REVENUE';

                $grossAmount    = round($qty * $dnItem->price, 2);
                $discountAmount = round($grossAmount * ($dnItem->discount_pct / 100), 2);
                $netAfterDisc   = $grossAmount - $discountAmount;

                $taxAmount  = 0.0;
                $taxAccount = null;
                if ($item->tax_type_id) {
                    $taxType = DB::table('tax_types')->where('id', $item->tax_type_id)->first();
                    if ($taxType && (float) $taxType->default_rate > 0) {
                        $taxAmount  = round($netAfterDisc * (float) $taxType->default_rate / 100, 2);
                        $taxAccount = $taxType->sales_gl_account ?: null;
                    }
                }

                $totalGrossSales += $grossAmount;
                $totalDiscounts  += $discountAmount;
                $totalTax        += $taxAmount;

                // CR Sales Revenue (gross, before discount)
                GldTransaction::create([
                    'trans_no'     => $delivery->id,
                    'type'         => StockMovement::TYPE_DELIVERY,
                    'tran_date'    => $tranDate,
                    'account_code' => $salesAccount,
                    'reference'    => $dnNo,
                    'narration'    => "Sales — {$dnItem->description} ({$dnNo})",
                    'amount'       => -$grossAmount,
                    'created_by'   => $createdBy,
                    'dimension_id' => $dimId,
                    'dimension2_id'=> $dim2Id,
                ]);

                // CR Tax Payable
                if ($taxAccount && $taxAmount != 0) {
                    GldTransaction::create([
                        'trans_no'     => $delivery->id,
                        'type'         => StockMovement::TYPE_DELIVERY,
                        'tran_date'    => $tranDate,
                        'account_code' => $taxAccount,
                        'reference'    => $dnNo,
                        'narration'    => "Tax — {$dnItem->description} ({$dnNo})",
                        'amount'       => -$taxAmount,
                        'created_by'   => $createdBy,
                        'dimension_id' => $dimId,
                        'dimension2_id'=> $dim2Id,
                    ]);
                }

                // DR Discount (contra-revenue — positive = debit)
                if ($discountAmount != 0) {
                    $discountGlCode = ($company->discount_gl_code ?? null)
                        ?: ($glSetting->sales_discount_account ?? null)
                        ?: 'SALES_DISCOUNT';
                    GldTransaction::create([
                        'trans_no'     => $delivery->id,
                        'type'         => StockMovement::TYPE_DELIVERY,
                        'tran_date'    => $tranDate,
                        'account_code' => $discountGlCode,
                        'reference'    => $dnNo,
                        'narration'    => "Discount — {$dnItem->description} ({$dnNo})",
                        'amount'       => $discountAmount,
                        'created_by'   => $createdBy,
                        'dimension_id' => $dimId,
                        'dimension2_id'=> $dim2Id,
                    ]);
                }
            }

            // ── CR Shipping Income ────────────────────────────────────────────
            $shippingCharge = (float) ($delivery->shipping_charge ?? 0);
            if ($shippingCharge != 0) {
                $shippingAccount = ($glSetting->shipping_charged_account ?: null) ?: 'SHIPPING_INCOME';
                GldTransaction::create([
                    'trans_no'     => $delivery->id,
                    'type'         => StockMovement::TYPE_DELIVERY,
                    'tran_date'    => $tranDate,
                    'account_code' => $shippingAccount,
                    'reference'    => $dnNo,
                    'narration'    => "Shipping — {$dnNo}",
                    'amount'       => -$shippingCharge,
                    'created_by'   => $createdBy,
                    'dimension_id' => $delivery->dimension_id,
                    'dimension2_id'=> $delivery->dimension2_id,
                ]);
            }

            // ── DR Debtors (full amount_total) ────────────────────────────────
            // Debtors = gross - discounts + tax + shipping
            $debtorsAmount = round($totalGrossSales - $totalDiscounts + $totalTax + $shippingCharge, 2);
            if ($debtorsAmount != 0) {
                $debtorsGlCode = ($company->debtors_gl_code ?? null) ?: 'DEBTORS';
                GldTransaction::create([
                    'trans_no'     => $delivery->id,
                    'type'         => StockMovement::TYPE_DELIVERY,
                    'tran_date'    => $tranDate,
                    'account_code' => $debtorsGlCode,
                    'reference'    => $dnNo,
                    'narration'    => "Debtors — {$dnNo}",
                    'amount'       => round($debtorsAmount, 2),
                    'created_by'   => $createdBy,
                    'dimension_id' => $delivery->dimension_id,
                    'dimension2_id'=> $delivery->dimension2_id,
                ]);
            }

            try {
                broadcast(new DashboardEvent('delivery', 'placed', ['ref' => $delivery->dn_no, 'amount' => $delivery->amount_total]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }
            return ApiResponse::success($delivery, 'Sales delivery placed');
        });
    }

    public function cancel(int $id): JsonResponse
    {
        $delivery = SalesDelivery::find($id);
        if (! $delivery) {
            return ApiResponse::notFound('Sales delivery not found');
        }
        if ($delivery->status === 'placed') {
            return ApiResponse::error('Cannot cancel a placed delivery', 422);
        }

        $delivery->update(['status' => 'cancelled']);
        try {
            broadcast(new DashboardEvent('delivery', 'cancelled', ['ref' => $delivery->dn_no]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
        }
        return ApiResponse::success($delivery, 'Sales delivery cancelled');
    }

    public function addItem(Request $request, int $id): JsonResponse
    {
        $delivery = SalesDelivery::find($id);
        if (! $delivery) {
            return ApiResponse::notFound('Sales delivery not found');
        }

        $validated = $request->validate([
            'stock_id'      => 'required|string|max:20',
            'description'   => 'required|string|max:200',
            'qty'           => 'required|numeric|min:0.0001',
            'price'         => 'required|numeric|min:0',
            'discount_pct'  => 'nullable|numeric|min:0|max:100',
            'unit'          => 'nullable|string|max:20',
            'standard_cost' => 'nullable|numeric|min:0',
        ]);

        $priceError = $this->checkSingleItemPrice($validated['stock_id'], (float) $validated['price']);
        if ($priceError) {
            return ApiResponse::validationError(['price' => $priceError]);
        }

        $lineTotal = $this->calcLineTotal($validated);

        $item = SalesDeliveryItem::create([
            'delivery_id'   => $delivery->id,
            'stock_id'      => $validated['stock_id'],
            'description'   => $validated['description'],
            'qty'           => $validated['qty'],
            'unit'          => $validated['unit'] ?? null,
            'price'         => $validated['price'],
            'standard_cost' => $validated['standard_cost'] ?? 0,
            'discount_pct'  => $validated['discount_pct'] ?? 0,
            'line_total'    => $lineTotal,
        ]);

        $this->recalcTotals($delivery);

        return ApiResponse::created($item, 'Item added');
    }

    public function updateItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $item = SalesDeliveryItem::where('delivery_id', $id)->where('id', $itemId)->first();
        if (! $item) {
            return ApiResponse::notFound('Item not found');
        }

        $validated = $request->validate([
            'qty'          => 'sometimes|numeric|min:0.0001',
            'price'        => 'sometimes|numeric|min:0',
            'discount_pct' => 'nullable|numeric|min:0|max:100',
            'description'  => 'sometimes|string|max:200',
            'unit'         => 'nullable|string|max:20',
        ]);

        $item->fill($validated);
        $item->line_total = $this->calcLineTotal($item->toArray());
        $item->save();

        $delivery = SalesDelivery::find($id);
        if ($delivery) $this->recalcTotals($delivery);

        return ApiResponse::updated($item, 'Item updated');
    }

    public function removeItem(int $id, int $itemId): JsonResponse
    {
        $item = SalesDeliveryItem::where('delivery_id', $id)->where('id', $itemId)->first();
        if (! $item) {
            return ApiResponse::notFound('Item not found');
        }

        $item->delete();

        $delivery = SalesDelivery::find($id);
        if ($delivery) $this->recalcTotals($delivery);

        return ApiResponse::deleted('Item removed');
    }

    public function glEntries(int $id): JsonResponse
    {
        $delivery = SalesDelivery::with('customer')->find($id);
        if (! $delivery) return ApiResponse::notFound('Delivery not found');

        $company = DB::table('company_preferences')->first();

        $entries = GldTransaction::where('type', StockMovement::TYPE_DELIVERY)
            ->where('trans_no', $id)
            ->orderBy('id')
            ->get();

        $enriched = $entries->map(function ($e) {
            $accountName = DB::table('gl_accounts')
                ->where('code', $e->account_code)
                ->value('name') ?? $e->account_code;
            $dim1 = $e->dimension_id
                ? DB::table('dimensions')->where('id', $e->dimension_id)->selectRaw("CONCAT(reference, ' ', name) as label")->value('label')
                : null;
            $dim2 = $e->dimension2_id
                ? DB::table('dimensions')->where('id', $e->dimension2_id)->selectRaw("CONCAT(reference, ' ', name) as label")->value('label')
                : null;
            return [
                'tran_date'    => $e->tran_date,
                'account_code' => $e->account_code,
                'account_name' => $accountName,
                'narration'    => $e->narration,
                'amount'       => (float) $e->amount,
                'dimension1'   => $dim1,
                'dimension2'   => $dim2,
            ];
        });

        $totalDebit  = $enriched->where('amount', '>', 0)->sum('amount');
        $totalCredit = abs($enriched->where('amount', '<', 0)->sum('amount'));

        return ApiResponse::success([
            'company'      => $company,
            'delivery'     => [
                'id'            => $delivery->id,
                'dn_no'         => $delivery->dn_no,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'customer_name' => $delivery->customer?->name,
                'debtor_no'     => $delivery->debtor_no,
            ],
            'entries'      => $enriched,
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
        ], 'GL entries retrieved');
    }

    public function forInvoice(int $id): JsonResponse
    {
        $delivery = SalesDelivery::with(['items', 'customer'])->find($id);
        if (! $delivery) return ApiResponse::notFound('Delivery not found');

        $branchName = $delivery->branch_id
            ? DB::table('customer_branches')->where('id', $delivery->branch_id)->value('branch_name')
            : null;

        // Quantities already invoiced against this delivery
        $invoicedQtys = DB::table('sales_invoice_items as ii')
            ->join('sales_invoices as i', 'ii.inv_id', '=', 'i.id')
            ->where('i.dn_id', $id)
            ->where('i.status', 'placed')
            ->groupBy('ii.stock_id')
            ->selectRaw('ii.stock_id, SUM(ii.qty) as invoiced_qty')
            ->pluck('invoiced_qty', 'stock_id');

        // Quantities already returned — returned goods can't be invoiced again
        $returnedQtys = $this->returnedQtysByStockId($id);

        $items = $delivery->items->map(function ($it) use ($invoicedQtys, $returnedQtys) {
            $invoiced = (float) ($invoicedQtys[$it->stock_id] ?? 0);
            $returned = (float) ($returnedQtys[$it->stock_id] ?? 0);
            return [
                'id'            => $it->id,
                'stock_id'      => $it->stock_id,
                'description'   => $it->description,
                'delivered_qty' => (float) $it->qty,
                'unit'          => $it->unit,
                'invoiced_qty'  => $invoiced,
                'returned_qty'  => $returned,
                'this_qty'      => max(0, (float) $it->qty - $invoiced - $returned),
                'price'         => (float) $it->price,
                'discount_pct'  => (float) $it->discount_pct,
                'standard_cost' => (float) $it->standard_cost,
            ];
        });

        $salesTypes        = DB::table('sales_types')->get(['id', 'type_name']);
        $shippingCompanies = DB::table('shipping_companies')->get(['id', 'name']);
        $paymentTermsList  = DB::table('payment_terms')->get(['id', 'description']);
        $paymentTermsName  = $delivery->payment_terms
            ? DB::table('payment_terms')->where('id', $delivery->payment_terms)->value('description')
            : null;

        return ApiResponse::success([
            'delivery' => [
                'id'                  => $delivery->id,
                'dn_no'               => $delivery->dn_no,
                'debtor_no'           => $delivery->debtor_no,
                'customer_name'       => $delivery->customer?->name,
                'currency'            => $delivery->customer?->currency ?? 'KES',
                'branch_id'           => $delivery->branch_id,
                'branch_name'         => $branchName,
                'delivery_date'       => $delivery->delivery_date?->toDateString(),
                'invoice_before'      => $delivery->invoice_before?->toDateString(),
                'payment_terms'       => $delivery->payment_terms,
                'payment_terms_name'  => $paymentTermsName,
                'dimension_id'        => $delivery->dimension_id,
                'dimension2_id'       => $delivery->dimension2_id,
                'vehicle'             => $delivery->vehicle,
                'shift'               => $delivery->shift,
                'customer_ref'        => $delivery->customer_ref,
                'shipping_company_id' => $delivery->shipping_company_id,
                'shipping_charge'     => (float) $delivery->shipping_charge,
                'sub_total'           => (float) $delivery->sub_total,
                'amount_total'        => (float) $delivery->amount_total,
                'deliver_to'          => $delivery->deliver_to,
                'address'             => $delivery->address,
                'contact_phone'       => $delivery->contact_phone,
                'comments'            => $delivery->comments,
            ],
            'items'              => $items,
            'next_ref'           => SalesInvoice::nextInvNo(),
            'sales_types'        => $salesTypes,
            'shipping_companies' => $shippingCompanies,
            'payment_terms_list' => $paymentTermsList,
        ], 'Delivery for invoice');
    }

    public function createInvoice(Request $request, int $id): JsonResponse
    {
        $delivery = SalesDelivery::with('items')->find($id);
        if (! $delivery) return ApiResponse::notFound('Delivery not found');
        if ($delivery->status !== 'placed') {
            return ApiResponse::error('Delivery must be placed before it can be invoiced', 422);
        }

        $data = $request->validate([
            'invoice_date'        => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'due_date'            => 'nullable|date',
            'payment_terms'       => 'nullable|integer',
            'sales_type_id'       => 'nullable|integer',
            'shipping_company_id' => 'nullable|integer',
            'shipping_charge'     => 'nullable|numeric|min:0',
            'dimension_id'        => 'nullable|integer',
            'dimension2_id'       => 'nullable|integer',
            'vehicle'             => 'nullable|string|max:50',
            'shift'               => 'nullable|string|max:20',
            'comments'            => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.dn_item_id'  => 'required|integer',
            'items.*.qty'         => 'required|numeric|min:0.0001',
            'items.*.price'       => 'required|numeric|min:0',
            'items.*.discount_pct'=> 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($delivery, $data) {
            $createdBy  = auth()->user()?->user_id ?? 'system';
            $tranDate   = $data['invoice_date'];
            $glSetting  = DB::table('gl_settings')->first();
            $company    = DB::table('company_preferences')->first();

            $invoice = SalesInvoice::create([
                'inv_no'              => SalesInvoice::nextInvNo(),
                'dn_id'               => $delivery->id,
                'so_id'               => $delivery->so_id,
                'debtor_no'           => $delivery->debtor_no,
                'branch_id'           => $delivery->branch_id,
                'invoice_date'        => $tranDate,
                'due_date'            => $data['due_date'] ?? null,
                'payment_terms'       => $data['payment_terms'] ?? $delivery->payment_terms,
                'price_list_id'       => $delivery->price_list_id,
                'shipping_charge'     => $data['shipping_charge'] ?? 0,
                'dimension_id'        => $data['dimension_id'] ?? $delivery->dimension_id,
                'dimension2_id'       => $data['dimension2_id'] ?? $delivery->dimension2_id,
                'vehicle'             => $data['vehicle'] ?? $delivery->vehicle,
                'shift'               => $data['shift'] ?? $delivery->shift,
                'deliver_to'          => $delivery->deliver_to,
                'address'             => $delivery->address,
                'contact_phone'       => $delivery->contact_phone,
                'customer_ref'        => $delivery->customer_ref,
                'comments'            => $data['comments'] ?? null,
                'shipping_company_id' => $data['shipping_company_id'] ?? $delivery->shipping_company_id,
                'sub_total'           => 0,
                'amount_total'        => 0,
                'status'              => 'placed',
                'created_by'          => $createdBy,
            ]);

            $invNo         = $invoice->inv_no;
            $subTotal      = 0;
            $totalGrossSales = 0.0;

            foreach ($data['items'] as $itemData) {
                $dnItem = $delivery->items->firstWhere('id', $itemData['dn_item_id']);
                if (! $dnItem) continue;

                $qty          = (float) $itemData['qty'];
                $price        = (float) $itemData['price'];
                $discPct      = (float) ($itemData['discount_pct'] ?? 0);
                $lineTotal    = round($qty * $price * (1 - $discPct / 100), 2);
                $subTotal    += $lineTotal;

                $standardCost = (float) ($dnItem->standard_cost ?? 0);
                if ($standardCost == 0) {
                    $itemCost = DB::table('items')
                        ->where('stock_id', $dnItem->stock_id)
                        ->selectRaw('COALESCE(purchase_cost,0)+COALESCE(material_cost,0)+COALESCE(labour_cost,0)+COALESCE(overhead_cost,0) as c')
                        ->value('c');
                    $standardCost = (float) ($itemCost ?? 0);
                }

                SalesInvoiceItem::create([
                    'inv_id'        => $invoice->id,
                    'stock_id'      => $dnItem->stock_id,
                    'description'   => $dnItem->description,
                    'qty'           => $qty,
                    'unit'          => $dnItem->unit,
                    'price'         => $price,
                    'standard_cost' => $standardCost,
                    'discount_pct'  => $discPct,
                    'line_total'    => $lineTotal,
                ]);

                // Stock was already deducted when the delivery was placed
                // (SalesDeliveryController::place() / dispatchFromOrder()) —
                // invoicing a delivery only books revenue, it doesn't move stock again.

                // ── GL: Revenue / Tax / Discount ──────────────────────────────
                $item = DB::table('items')->where('stock_id', $dnItem->stock_id)
                    ->select('sales_account', 'tax_type_id', 'dimension_id', 'dimension2_id')
                    ->first();

                $dimId  = ($item->dimension_id  ?? null) ?: ($data['dimension_id']  ?? $delivery->dimension_id);
                $dim2Id = ($item->dimension2_id ?? null) ?: ($data['dimension2_id'] ?? $delivery->dimension2_id);

                // Fall back to the GL-wide default sales account when the item
                // itself has none configured — otherwise revenue (and therefore
                // every GL line for this invoice) silently never gets posted.
                $salesAccount = ($item->sales_account ?? null) ?: null;
                $salesAccount = $salesAccount
                    ?: ($glSetting->items_sales_account ?: null)
                    ?: ($glSetting->sales_account ?: null)
                    ?: 'SALES_REVENUE';

                {
                    $gross      = round($qty * $price, 4);
                    $discAmt    = round($gross * $discPct / 100, 4);
                    $netRevenue = $gross - $discAmt;
                    $taxAmount  = 0.0; $taxAccount = null;

                    if ($item && $item->tax_type_id) {
                        $taxType = DB::table('tax_types')->where('id', $item->tax_type_id)->first();
                        if ($taxType && (float) $taxType->default_rate > 0) {
                            $taxAmount  = round($netRevenue * (float) $taxType->default_rate / 100, 4);
                            $taxAccount = $taxType->sales_gl_account ?: null;
                        }
                    }

                    $totalGrossSales += $gross;

                    GldTransaction::create([
                        'trans_no' => $invoice->id, 'type' => StockMovement::TYPE_INVOICE,
                        'tran_date' => $tranDate, 'account_code' => $salesAccount,
                        'reference' => $invNo, 'narration' => "Sales — {$dnItem->description} ({$invNo})",
                        'amount' => -($netRevenue - $taxAmount), 'created_by' => $createdBy,
                        'dimension_id' => $dimId, 'dimension2_id' => $dim2Id,
                    ]);

                    if ($taxAccount && $taxAmount != 0) {
                        GldTransaction::create([
                            'trans_no' => $invoice->id, 'type' => StockMovement::TYPE_INVOICE,
                            'tran_date' => $tranDate, 'account_code' => $taxAccount,
                            'reference' => $invNo, 'narration' => "Tax — {$dnItem->description} ({$invNo})",
                            'amount' => -$taxAmount, 'created_by' => $createdBy,
                            'dimension_id' => $dimId, 'dimension2_id' => $dim2Id,
                        ]);
                    }

                    if ($discAmt != 0) {
                        $discGl = ($company->discount_gl_code ?? null) ?: ($glSetting->sales_discount_account ?? 'DISCOUNT');
                        GldTransaction::create([
                            'trans_no' => $invoice->id, 'type' => StockMovement::TYPE_INVOICE,
                            'tran_date' => $tranDate, 'account_code' => $discGl,
                            'reference' => $invNo, 'narration' => "Discount — {$dnItem->description} ({$invNo})",
                            'amount' => -$discAmt, 'created_by' => $createdBy,
                            'dimension_id' => $dimId, 'dimension2_id' => $dim2Id,
                        ]);
                    }
                }
            }

            // ── GL: DR Debtors ────────────────────────────────────────────────
            if ($totalGrossSales != 0) {
                $debtorsGl = ($company->debtors_gl_code ?? null) ?: 'DEBTORS';
                GldTransaction::create([
                    'trans_no' => $invoice->id, 'type' => StockMovement::TYPE_INVOICE,
                    'tran_date' => $tranDate, 'account_code' => $debtorsGl,
                    'reference' => $invNo, 'narration' => "Debtors — {$invNo}",
                    'amount' => round($totalGrossSales, 2), 'created_by' => $createdBy,
                    'dimension_id' => $delivery->dimension_id, 'dimension2_id' => $delivery->dimension2_id,
                ]);
            }

            $shippingCharge = (float) ($data['shipping_charge'] ?? 0);
            $invoice->update([
                'sub_total'    => round($subTotal, 2),
                'amount_total' => round($subTotal + $shippingCharge, 2),
            ]);

            $delivery->update(['to_invoice' => false]);

            return ApiResponse::created($invoice->fresh()->load('items'), 'Invoice created');
        });
    }

    public function forReturn(int $id): JsonResponse
    {
        $delivery = SalesDelivery::with(['items', 'customer'])->find($id);
        if (! $delivery) return ApiResponse::notFound('Delivery not found');

        // Quantities already invoiced against this delivery
        $invoicedQtys = DB::table('sales_invoice_items as ii')
            ->join('sales_invoices as i', 'ii.inv_id', '=', 'i.id')
            ->where('i.dn_id', $id)
            ->where('i.status', 'placed')
            ->groupBy('ii.stock_id')
            ->selectRaw('ii.stock_id, SUM(ii.qty) as invoiced_qty')
            ->pluck('invoiced_qty', 'stock_id');

        // Original location each item was drawn from (negative delivery movement)
        $itemLocCodes = DB::table('stock_movements')
            ->where('trans_no', $id)
            ->where('type', StockMovement::TYPE_DELIVERY)
            ->where('qty', '<', 0)
            ->groupBy('stock_id', 'loc_code')
            ->selectRaw('TRIM(stock_id) as stock_id, loc_code')
            ->pluck('loc_code', 'stock_id');

        // Resolve loc_code → location name
        $locCodes  = $itemLocCodes->values()->filter()->unique()->values();
        $locNames  = DB::table('inventory_locations')
            ->whereIn('code', $locCodes)
            ->pluck('name', 'code');

        // Goods already returned against this delivery — can't be returned twice
        $returnedQtys = $this->returnedQtysByStockId($id);

        // "Return Delivery Note" only ever reverses stock — it posts no GL lines,
        // so it can only safely cover the portion that was never invoiced (no
        // revenue/Debtors was ever booked for it). Already-invoiced goods need a
        // proper financial reversal — that's what Credit Notes are for.
        $items = $delivery->items->map(function ($it) use ($invoicedQtys, $returnedQtys, $itemLocCodes, $locNames) {
            $locCode  = $itemLocCodes[$it->stock_id] ?? null;
            $invoiced = (float) ($invoicedQtys[$it->stock_id] ?? 0);
            $returned = (float) ($returnedQtys[$it->stock_id] ?? 0);
            $available = max(0, (float) $it->qty - $invoiced - $returned);
            return [
                'id'           => $it->id,
                'stock_id'     => $it->stock_id,
                'description'  => $it->description,
                'unit'         => $it->unit,
                'original_qty' => (float) $it->qty,
                'invoiced_qty' => $invoiced,
                'returned_qty' => $returned,
                'available_qty'=> $available,
                'return_qty'   => $available,
                'loc_code'     => $locCode,
                'loc_name'     => $locCode ? ($locNames[$locCode] ?? $locCode) : null,
            ];
        });

        $branchName = $delivery->branch_id
            ? DB::table('customer_branches')->where('id', $delivery->branch_id)->value('branch_name')
            : null;

        return ApiResponse::success([
            'delivery' => [
                'id'            => $delivery->id,
                'dn_no'         => $delivery->dn_no,
                'debtor_no'     => $delivery->debtor_no,
                'customer_name' => $delivery->customer?->name,
                'currency'      => $delivery->customer?->currency ?? 'KES',
                'branch_id'     => $delivery->branch_id,
                'branch_name'   => $branchName,
                'delivery_date' => $delivery->delivery_date?->toDateString(),
                'location_id'   => $delivery->location_id,
                'vehicle'       => $delivery->vehicle,
                'shift'         => $delivery->shift,
                'comments'      => $delivery->comments,
                'sub_total'     => (float) $delivery->sub_total,
                'amount_total'  => (float) $delivery->amount_total,
            ],
            'items'     => $items,
            'return_ref'=> 'RDN/' . now()->format('n/Y'),
        ], 'Return data retrieved');
    }

    public function processReturn(Request $request, int $id): JsonResponse
    {
        $delivery = SalesDelivery::with('items')->find($id);
        if (! $delivery) return ApiResponse::notFound('Delivery not found');

        $data = $request->validate([
            'return_date'        => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'comments'           => 'nullable|string',
            'items'              => 'required|array|min:1',
            'items.*.dn_item_id' => 'required|integer',
            'items.*.return_qty' => 'required|numeric|min:0',
            'items.*.loc_code'   => 'nullable|string',
        ]);

        // Re-validate against the server's own "available to return" figure —
        // a stale or tampered request must not be able to return more than is
        // actually available. This screen posts no GL lines, so only the
        // not-yet-invoiced portion is returnable here (mirrors forReturn()'s
        // available_qty); already-invoiced goods need a Credit Note instead.
        $invoicedQtys = DB::table('sales_invoice_items as ii')
            ->join('sales_invoices as i', 'ii.inv_id', '=', 'i.id')
            ->where('i.dn_id', $delivery->id)
            ->where('i.status', 'placed')
            ->groupBy('ii.stock_id')
            ->selectRaw('ii.stock_id, SUM(ii.qty) as invoiced_qty')
            ->pluck('invoiced_qty', 'stock_id');

        $returnedQtys = $this->returnedQtysByStockId($delivery->id);

        $errors = [];
        foreach ($data['items'] as $itemData) {
            if (floatval($itemData['return_qty']) <= 0) continue;
            $dnItem = $delivery->items->firstWhere('id', $itemData['dn_item_id']);
            if (! $dnItem) continue;

            $invoiced  = (float) ($invoicedQtys[$dnItem->stock_id] ?? 0);
            $returned  = (float) ($returnedQtys[$dnItem->stock_id] ?? 0);
            $available = max(0, (float) $dnItem->qty - $invoiced - $returned);
            if (floatval($itemData['return_qty']) > $available) {
                $errors[] = "Return qty for \"{$dnItem->description}\" ({$itemData['return_qty']}) exceeds available ({$available}).";
            }
        }
        if (! empty($errors)) {
            return ApiResponse::validationError(['items' => $errors]);
        }

        return DB::transaction(function () use ($delivery, $data) {
            foreach ($data['items'] as $itemData) {
                if (floatval($itemData['return_qty']) <= 0) continue;
                $dnItem = $delivery->items->firstWhere('id', $itemData['dn_item_id']);
                if (! $dnItem) continue;

                // Positive movement = returning stock to the original source location
                StockMovement::create([
                    'trans_no'   => $delivery->id,
                    'stock_id'   => $dnItem->stock_id,
                    'type'       => StockMovement::TYPE_DELIVERY,
                    'qty'        => abs(floatval($itemData['return_qty'])),
                    'loc_code'   => $itemData['loc_code'] ?? null,
                    'tran_date'  => $data['return_date'],
                    'date_moved' => now(),
                    'price'      => $dnItem->price,
                    'reference'  => 'RETURN-' . $delivery->dn_no,
                ]);
            }

            return ApiResponse::success(['dn_no' => $delivery->dn_no], 'Return processed successfully');
        });
    }
}
