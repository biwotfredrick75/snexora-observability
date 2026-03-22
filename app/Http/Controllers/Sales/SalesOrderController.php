<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesOrder;
use App\Models\SalesDelivery;
use App\Models\SalesDeliveryItem;
use App\Models\SalesOrderItem;
use App\Models\StockMovement;
use App\Models\GldTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recalcTotals(SalesOrder $order): void
    {
        $subTotal = $order->items()->get()->sum(function ($item) {
            return $item->qty * $item->price * (1 - $item->discount_pct / 100);
        });
        $order->sub_total    = round($subTotal, 2);
        $order->amount_total = round($subTotal + $order->shipping_charge, 2);
        $order->save();
    }

    private function calcLineTotal(array $item): float
    {
        return round(
            floatval($item['qty']) * floatval($item['price']) * (1 - floatval($item['discount_pct'] ?? 0) / 100),
            2
        );
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function nextRef(): JsonResponse
    {
        $ref = DB::transaction(fn() => SalesOrder::nextSoNo());
        return ApiResponse::success(['ref' => $ref], 'Next reference generated');
    }

    public function index(Request $request): JsonResponse
    {
        $query = SalesOrder::with(['customer:debtor_no,name'])->orderByDesc('id');

        if ($v = $request->get('so_no'))     $query->where('so_no', 'like', "%{$v}%");
        if ($v = $request->get('ref'))       $query->where('customer_ref', 'like', "%{$v}%");
        if ($v = $request->get('debtor_no')) $query->where('debtor_no', $v);
        if ($v = $request->get('status'))    $query->where('status', $v);
        if ($v = $request->get('date_from')) $query->where('order_date', '>=', $v);
        if ($v = $request->get('date_to'))   $query->where('order_date', '<=', $v);

        $orders = $query->paginate(min((int) $request->get('per_page', 30), 200));

        return ApiResponse::paginated($orders, 'Orders retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'debtor_no'       => 'required|string|max:10',
            'order_date'      => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'branch_id'       => 'nullable|integer',
            'delivery_date'   => 'nullable|date',
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

        return DB::transaction(function () use ($validated) {
            $order = SalesOrder::create([
                'so_no'              => 'TEMP-' . uniqid(),
                'debtor_no'          => $validated['debtor_no'],
                'branch_id'          => $validated['branch_id'] ?? null,
                'order_date'         => $validated['order_date'],
                'delivery_date'      => $validated['delivery_date'] ?? null,
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

            $order->update(['so_no' => SalesOrder::nextSoNo()]);

            $subTotal = 0;
            foreach ($validated['items'] as $itemData) {
                $lineTotal = $this->calcLineTotal($itemData);
                $subTotal += $lineTotal;
                SalesOrderItem::create([
                    'so_id'         => $order->id,
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

            $order->sub_total    = round($subTotal, 2);
            $order->amount_total = round($subTotal + ($validated['shipping_charge'] ?? 0), 2);
            $order->save();

            return ApiResponse::created($order->load('items'), 'Sales order created');
        });
    }

    public function show(int $id): JsonResponse
    {
        $order = SalesOrder::with(['items', 'customer'])->find($id);
        if (! $order) {
            return ApiResponse::notFound('Sales order not found');
        }
        return ApiResponse::success($order, 'Sales order retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $order = SalesOrder::find($id);
        if (! $order) {
            return ApiResponse::notFound('Sales order not found');
        }

        $validated = $request->validate([
            'order_date'      => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'delivery_date'   => 'nullable|date',
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

        $order->update($validated);
        $this->recalcTotals($order);

        return ApiResponse::updated($order->load('items'), 'Sales order updated');
    }

    public function place(int $id): JsonResponse
    {
        $order = SalesOrder::find($id);
        if (! $order) {
            return ApiResponse::notFound('Sales order not found');
        }
        if ($order->status !== 'draft') {
            return ApiResponse::error('Order is not in draft status', 422);
        }

        $order->update(['status' => 'placed']);
        return ApiResponse::success($order, 'Sales order placed');
    }

    public function cancel(int $id): JsonResponse
    {
        $order = SalesOrder::find($id);
        if (! $order) {
            return ApiResponse::notFound('Sales order not found');
        }
        if ($order->status === 'placed') {
            return ApiResponse::error('Cannot cancel a placed order', 422);
        }

        $order->update(['status' => 'cancelled']);
        return ApiResponse::success($order, 'Sales order cancelled');
    }

    public function addItem(Request $request, int $id): JsonResponse
    {
        $order = SalesOrder::find($id);
        if (! $order) {
            return ApiResponse::notFound('Sales order not found');
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

        $lineTotal = $this->calcLineTotal($validated);

        $item = SalesOrderItem::create([
            'so_id'         => $order->id,
            'stock_id'      => $validated['stock_id'],
            'description'   => $validated['description'],
            'qty'           => $validated['qty'],
            'unit'          => $validated['unit'] ?? null,
            'price'         => $validated['price'],
            'standard_cost' => $validated['standard_cost'] ?? 0,
            'discount_pct'  => $validated['discount_pct'] ?? 0,
            'line_total'    => $lineTotal,
        ]);

        $this->recalcTotals($order);

        return ApiResponse::created($item, 'Item added');
    }

    public function updateItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $item = SalesOrderItem::where('so_id', $id)->where('id', $itemId)->first();
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

        $order = SalesOrder::find($id);
        if ($order) $this->recalcTotals($order);

        return ApiResponse::updated($item, 'Item updated');
    }

    public function removeItem(int $id, int $itemId): JsonResponse
    {
        $item = SalesOrderItem::where('so_id', $id)->where('id', $itemId)->first();
        if (! $item) {
            return ApiResponse::notFound('Item not found');
        }

        $item->delete();

        $order = SalesOrder::find($id);
        if ($order) $this->recalcTotals($order);

        return ApiResponse::deleted('Item removed');
    }

    // ── Outstanding (placed) orders list ─────────────────────────────────────

    public function outstanding(Request $request): JsonResponse
    {
        $query = DB::table('sales_orders as so')
            ->join('customers as c', 'so.debtor_no', '=', 'c.debtor_no')
            ->leftJoin('customer_branches as cb', 'so.branch_id', '=', 'cb.id')
            ->whereIn('so.status', ['placed', 'draft'])
            ->select(
                'so.id', 'so.so_no', 'so.debtor_no', 'so.status',
                'c.name as customer_name', 'c.currency',
                'cb.branch_name',
                'so.order_date', 'so.delivery_date',
                'so.deliver_to', 'so.customer_ref', 'so.amount_total'
            );

        if ($request->filled('q')) {
            $q = $request->get('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('so.so_no', 'like', "%{$q}%")
                    ->orWhere('c.name', 'like', "%{$q}%")
                    ->orWhere('so.customer_ref', 'like', "%{$q}%");
            });
        }
        if ($request->filled('debtor_no'))  $query->where('so.debtor_no', $request->get('debtor_no'));
        if ($request->filled('date_from'))  $query->where('so.order_date', '>=', $request->get('date_from'));
        if ($request->filled('date_to'))    $query->where('so.order_date', '<=', $request->get('date_to'));

        // Exclude orders where every item has already been fully delivered
        $query->whereRaw('(
            SELECT COALESCE(SUM(soi.qty), 0)
            FROM sales_order_items soi
            WHERE soi.so_id = so.id
        ) > (
            SELECT COALESCE(SUM(di.qty), 0)
            FROM sales_delivery_items di
            JOIN sales_deliveries d ON di.delivery_id = d.id
            WHERE d.so_id = so.id AND d.status = \'placed\'
        )');

        $orders = $query->orderBy('so.order_date')->get();

        return ApiResponse::success($orders, 'Outstanding orders retrieved');
    }

    // ── Full order detail view (with deliveries, invoices, credit notes) ────────

    public function detail(int $id): JsonResponse
    {
        $order = SalesOrder::with(['items', 'customer'])->find($id);
        if (! $order) return ApiResponse::notFound('Sales order not found');

        // Payment terms name
        $paymentTermsName = $order->payment_terms
            ? DB::table('payment_terms')->where('id', $order->payment_terms)->value('description')
            : null;

        // Branch name
        $branchName = $order->branch_id
            ? DB::table('customer_branches')->where('id', $order->branch_id)->value('branch_name')
            : null;

        // Location name (deliver from)
        $locationName = $order->location_id
            ? DB::table('inventory_locations')->where('id', $order->location_id)->value('name')
            : null;

        // Deliveries linked to this order
        $deliveries = DB::table('sales_deliveries as d')
            ->where('d.so_id', $id)
            ->select('d.id', 'd.dn_no', 'd.delivery_date', 'd.amount_total', 'd.status', 'd.customer_ref')
            ->orderBy('d.id')
            ->get()
            ->map(fn($d) => [
                'id'           => $d->id,
                'dn_no'        => $d->dn_no,
                'delivery_date'=> $d->delivery_date,
                'total'        => (float) $d->amount_total,
                'status'       => $d->status,
                'ref'          => $d->customer_ref,
            ]);

        // Invoices linked to this order (via deliveries or directly)
        $invoices = DB::table('sales_invoices as i')
            ->where('i.so_id', $id)
            ->select('i.id', 'i.inv_no', 'i.invoice_date', 'i.amount_total', 'i.status', 'i.customer_ref')
            ->orderBy('i.id')
            ->get()
            ->map(fn($i) => [
                'id'           => $i->id,
                'inv_no'       => $i->inv_no,
                'invoice_date' => $i->invoice_date,
                'total'        => (float) $i->amount_total,
                'status'       => $i->status,
                'ref'          => $i->customer_ref,
            ]);

        // Qty delivered per stock_id for this order
        $deliveredQtys = DB::table('sales_delivery_items as di')
            ->join('sales_deliveries as d', 'di.delivery_id', '=', 'd.id')
            ->where('d.so_id', $id)
            ->whereIn('d.status', ['placed', 'invoiced'])
            ->groupBy('di.stock_id')
            ->selectRaw('di.stock_id, SUM(di.qty) as delivered_qty')
            ->pluck('delivered_qty', 'stock_id');

        $items = $order->items->map(fn($it) => [
            'id'            => $it->id,
            'stock_id'      => $it->stock_id,
            'description'   => $it->description,
            'qty'           => (float) $it->qty,
            'unit'          => $it->unit,
            'price'         => (float) $it->price,
            'discount_pct'  => (float) $it->discount_pct,
            'line_total'    => (float) $it->line_total,
            'delivered_qty' => (float) ($deliveredQtys[$it->stock_id] ?? 0),
        ]);

        $taxRate  = 16.0;
        $subTotal = $order->sub_total ?? $items->sum('line_total');
        $vatAmt   = round($subTotal * $taxRate / 100, 2);

        return ApiResponse::success([
            'id'                => $order->id,
            'so_no'             => $order->so_no,
            'status'            => $order->status,
            'debtor_no'         => $order->debtor_no,
            'customer_name'     => $order->customer?->name,
            'branch_name'       => $branchName,
            'currency'          => $order->customer?->currency ?? 'KES',
            'payment_terms'     => $order->payment_terms,
            'payment_terms_name'=> $paymentTermsName,
            'order_date'        => $order->order_date?->toDateString(),
            'delivery_date'     => $order->delivery_date?->toDateString(),
            'deliver_to'        => $order->deliver_to,
            'address'           => $order->address,
            'contact_phone'     => $order->contact_phone,
            'customer_ref'      => $order->customer_ref,
            'comments'          => $order->comments,
            'location_name'     => $locationName,
            'sub_total'         => (float) $subTotal,
            'vat_amount'        => $vatAmt,
            'amount_total'      => (float) ($order->amount_total ?? ($subTotal + $vatAmt)),
            'deliveries'        => $deliveries,
            'invoices'          => $invoices,
            'credit_notes'      => [],
            'items'             => $items,
        ], 'Order detail retrieved');
    }

    // ── Load order details for delivery form ──────────────────────────────────

    public function forDelivery(int $id): JsonResponse
    {
        $order = SalesOrder::with('items', 'customer')->find($id);
        if (! $order) {
            return ApiResponse::notFound('Order not found');
        }

        $branchName = $order->branch_id
            ? DB::table('customer_branches')->where('id', $order->branch_id)->value('branch_name')
            : null;

        // Quantities already dispatched against this order
        $deliveredQtys = DB::table('sales_delivery_items as di')
            ->join('sales_deliveries as d', 'di.delivery_id', '=', 'd.id')
            ->where('d.so_id', $id)
            ->where('d.status', 'placed')
            ->groupBy('di.stock_id')
            ->selectRaw('di.stock_id, SUM(di.qty) as delivered_qty')
            ->pluck('delivered_qty', 'stock_id');

        $items = $order->items->map(fn ($it) => [
            'id'            => $it->id,
            'stock_id'      => $it->stock_id,
            'description'   => $it->description,
            'ordered_qty'   => (float) $it->qty,
            'unit'          => $it->unit,
            'delivered_qty' => (float) ($deliveredQtys[$it->stock_id] ?? 0),
            'price'         => (float) $it->price,
            'discount_pct'  => (float) $it->discount_pct,
        ]);

        $fullyDelivered = $items->every(
            fn ($it) => $it['delivered_qty'] >= $it['ordered_qty']
        );

        return ApiResponse::success([
            'fully_delivered' => $fullyDelivered,
            'order' => [
                'id'                 => $order->id,
                'so_no'              => $order->so_no,
                'debtor_no'          => $order->debtor_no,
                'customer_name'      => $order->customer?->name,
                'currency'           => $order->customer?->currency ?? 'KES',
                'branch_id'          => $order->branch_id,
                'branch_name'        => $branchName,
                'order_date'         => $order->order_date?->toDateString(),
                'delivery_date'      => $order->delivery_date?->toDateString(),
                'location_id'        => $order->location_id,
                'vehicle'            => $order->vehicle,
                'shift'              => $order->shift,
                'deliver_to'         => $order->deliver_to,
                'address'            => $order->address,
                'contact_phone'      => $order->contact_phone,
                'customer_ref'       => $order->customer_ref,
                'shipping_company_id'=> $order->shipping_company_id,
                'amount_total'       => $order->amount_total,
            ],
            'items' => $items,
        ], 'Order for delivery');
    }

    // ── Create dispatch from sales order ──────────────────────────────────────

    public function dispatchFromOrder(Request $request, int $id): JsonResponse
    {
        $order = SalesOrder::with('items')->find($id);
        if (! $order) return ApiResponse::notFound('Order not found');
        if ($order->status !== 'placed') return ApiResponse::error('Order is not placed', 422);

        // Reject if all items are already fully delivered
        $deliveredQtys = DB::table('sales_delivery_items as di')
            ->join('sales_deliveries as d', 'di.delivery_id', '=', 'd.id')
            ->where('d.so_id', $id)
            ->where('d.status', 'placed')
            ->groupBy('di.stock_id')
            ->selectRaw('di.stock_id, SUM(di.qty) as delivered_qty')
            ->pluck('delivered_qty', 'stock_id');

        $hasRemaining = $order->items->contains(
            fn ($it) => (float) $it->qty > (float) ($deliveredQtys[$it->stock_id] ?? 0)
        );

        if (! $hasRemaining) {
            return ApiResponse::error('All items on this order have already been fully delivered.', 422);
        }

        $data = $request->validate([
            'delivery_date'       => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'location_id'         => 'nullable|integer',
            'vehicle'             => 'nullable|string|max:50',
            'shift'               => 'nullable|string|max:20',
            'shipping_company_id' => 'nullable|integer',
            'invoice_before'      => 'nullable|date',
            'shipping_charge'     => 'nullable|numeric|min:0',
            'comments'            => 'nullable|string',
            'items'               => 'required|array|min:1',
            'items.*.so_item_id'  => 'required|integer',
            'items.*.qty'         => 'required|numeric|min:0.0001',
        ]);

        return DB::transaction(function () use ($order, $data) {
            $locationId = $data['location_id'] ?? $order->location_id;

            // Resolve location code (varchar) from location ID for stock movements
            $locCode = $locationId
                ? DB::table('inventory_locations')->where('id', $locationId)->value('code')
                : null;

            $dnNo = SalesDelivery::nextDnNo();

            $delivery = SalesDelivery::create([
                'dn_no'               => $dnNo,
                'so_id'               => $order->id,
                'debtor_no'           => $order->debtor_no,
                'branch_id'           => $order->branch_id,
                'delivery_date'       => $data['delivery_date'],
                'invoice_before'      => $data['invoice_before'] ?? null,
                'payment_terms'       => $order->payment_terms,
                'price_list_id'       => $order->price_list_id,
                'dimension_id'        => $order->dimension_id,
                'dimension2_id'       => $order->dimension2_id,
                'location_id'         => $locationId,
                'vehicle'             => $data['vehicle'] ?? $order->vehicle,
                'shift'               => $data['shift'] ?? $order->shift,
                'deliver_to'          => $order->deliver_to,
                'address'             => $order->address,
                'contact_phone'       => $order->contact_phone,
                'customer_ref'        => $order->customer_ref,
                'comments'            => $data['comments'] ?? null,
                'shipping_company_id' => $data['shipping_company_id'] ?? $order->shipping_company_id,
                'shipping_charge'     => $data['shipping_charge'] ?? 0,
                'sub_total'           => 0,
                'amount_total'        => 0,
                'status'              => 'placed',
                'to_invoice'          => true,
                'created_by'          => auth()->user()?->user_id ?? null,
            ]);

            $subTotal  = 0;
            $createdBy = auth()->user()?->user_id ?? 'system';

            foreach ($data['items'] as $itemData) {
                $orderItem = $order->items->firstWhere('id', $itemData['so_item_id']);
                if (! $orderItem) continue;

                $qty          = (float) $itemData['qty'];
                $standardCost = (float) ($orderItem->standard_cost ?? 0);

                // Fall back to item master cost when order item has no standard_cost
                if ($standardCost == 0) {
                    $itemCost = DB::table('items')
                        ->where('stock_id', $orderItem->stock_id)
                        ->selectRaw('COALESCE(purchase_cost,0) + COALESCE(material_cost,0) + COALESCE(labour_cost,0) + COALESCE(overhead_cost,0) as total_cost')
                        ->value('total_cost');
                    $standardCost = (float) ($itemCost ?? 0);
                }

                $lineTotal    = round($qty * $orderItem->price * (1 - $orderItem->discount_pct / 100), 2);
                $subTotal    += $lineTotal;

                SalesDeliveryItem::create([
                    'delivery_id'   => $delivery->id,
                    'so_item_id'    => $orderItem->id,
                    'stock_id'      => $orderItem->stock_id,
                    'description'   => $orderItem->description,
                    'qty'           => $qty,
                    'unit'          => $orderItem->unit,
                    'price'         => $orderItem->price,
                    'standard_cost' => $standardCost,
                    'discount_pct'  => $orderItem->discount_pct,
                    'line_total'    => $lineTotal,
                ]);

                // ── Stock movement: goods out (negative qty) ──────────────────
                StockMovement::create([
                    'trans_no'      => $delivery->id,
                    'stock_id'      => $orderItem->stock_id,
                    'type'          => StockMovement::TYPE_DELIVERY,
                    'loc_code'      => $locCode,
                    'tran_date'     => $data['delivery_date'],
                    'date_moved'    => $data['delivery_date'],
                    'qty'           => -$qty,
                    'price'         => $orderItem->price,
                    'standard_cost' => $standardCost,
                    'reference'     => $dnNo,
                    'comments'      => $data['comments'] ?? null,
                    'user_name'     => $createdBy,
                    'vehicle'       => $data['vehicle'] ?? $order->vehicle ?? '',
                    'shift'         => $data['shift'] ?? $order->shift ?? '',
                    'approved'      => 1,
                ]);

                // ── Fetch item master data (accounts, tax) ───────────────────
                $item = DB::table('items')->where('stock_id', $orderItem->stock_id)
                    ->select('cogs_account', 'inventory_account', 'dimension_id', 'dimension2_id')
                    ->first();

                $dimId  = ($item->dimension_id  ?? null) ?: $order->dimension_id;
                $dim2Id = ($item->dimension2_id ?? null) ?: $order->dimension2_id;

                // ── 1. Cost-side: DR COGS / CR Inventory ─────────────────────
                if ($item && $item->cogs_account && $item->inventory_account) {
                    $cogsAmount = round($standardCost * $qty, 4);

                    if ($cogsAmount != 0) {
                        GldTransaction::create([
                            'trans_no'     => $delivery->id,
                            'type'         => StockMovement::TYPE_DELIVERY,
                            'tran_date'    => $data['delivery_date'],
                            'account_code' => $item->cogs_account,
                            'reference'    => $dnNo,
                            'narration'    => "COGS — {$orderItem->description} ({$dnNo})",
                            'amount'       => $cogsAmount,       // positive = debit
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);

                        GldTransaction::create([
                            'trans_no'     => $delivery->id,
                            'type'         => StockMovement::TYPE_DELIVERY,
                            'tran_date'    => $data['delivery_date'],
                            'account_code' => $item->inventory_account,
                            'reference'    => $dnNo,
                            'narration'    => "Inventory out — {$orderItem->description} ({$dnNo})",
                            'amount'       => -$cogsAmount,      // negative = credit
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);
                    }
                }

            }

            $shippingCharge = (float) ($data['shipping_charge'] ?? 0);
            $delivery->update([
                'sub_total'    => round($subTotal, 2),
                'amount_total' => round($subTotal + $shippingCharge, 2),
            ]);

            return ApiResponse::created($delivery->fresh()->load('items'), 'Dispatch created');
        });
    }
}
