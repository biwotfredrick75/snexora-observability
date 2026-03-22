<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesQuotationController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recalcTotals(SalesQuotation $quot): void
    {
        $subTotal = $quot->items()->get()->sum(fn ($it) => $it->qty * $it->price * (1 - $it->discount_pct / 100));
        $quot->sub_total    = round($subTotal, 2);
        $quot->amount_total = round($subTotal + $quot->shipping_charge, 2);
        $quot->save();
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
        $ref = DB::transaction(fn () => SalesQuotation::nextQuotNo());
        return ApiResponse::success(['ref' => $ref], 'Next reference generated');
    }

    public function index(Request $request): JsonResponse
    {
        $query = SalesQuotation::with(['customer:debtor_no,name'])->orderByDesc('id');

        if ($v = $request->get('q'))         $query->where(fn ($q) => $q->where('quot_no', 'like', "%{$v}%")->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$v}%")));
        if ($v = $request->get('debtor_no')) $query->where('debtor_no', $v);
        if ($v = $request->get('date_from')) $query->where('quotation_date', '>=', $v);
        if ($v = $request->get('date_to'))   $query->where('quotation_date', '<=', $v);
        if ($v = $request->get('status'))    $query->where('status', $v);

        $quotations = $query->paginate(min((int) $request->get('per_page', 30), 200));

        return ApiResponse::paginated($quotations, 'Quotations retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'debtor_no'           => 'required|string|max:10',
            'branch_id'           => 'nullable|integer',
            'quotation_date'      => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'valid_until'         => 'nullable|date',
            'payment_terms'       => 'nullable|integer',
            'price_list_id'       => 'nullable|integer',
            'shipping_charge'     => 'nullable|numeric|min:0',
            'dimension_id'        => 'nullable|integer',
            'dimension2_id'       => 'nullable|integer',
            'location_id'         => 'nullable|integer',
            'vehicle'             => 'nullable|string|max:50',
            'shift'               => 'nullable|string|max:20',
            'deliver_to'          => 'nullable|string|max:100',
            'address'             => 'nullable|string',
            'contact_phone'       => 'nullable|string|max:30',
            'customer_ref'        => 'nullable|string|max:60',
            'comments'            => 'nullable|string',
            'shipping_company_id' => 'nullable|integer',
            'items'               => 'required|array|min:1',
            'items.*.stock_id'    => 'required|string|max:20',
            'items.*.description' => 'required|string|max:200',
            'items.*.qty'         => 'required|numeric|min:0.0001',
            'items.*.price'       => 'required|numeric|min:0',
            'items.*.discount_pct'=> 'nullable|numeric|min:0|max:100',
            'items.*.unit'        => 'nullable|string|max:20',
            'items.*.standard_cost' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($validated) {
            $quot = SalesQuotation::create([
                'quot_no'            => SalesQuotation::nextQuotNo(),
                'debtor_no'          => $validated['debtor_no'],
                'branch_id'          => $validated['branch_id'] ?? null,
                'quotation_date'     => $validated['quotation_date'],
                'valid_until'        => $validated['valid_until'] ?? null,
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

            $subTotal = 0;
            foreach ($validated['items'] as $itemData) {
                $lineTotal = $this->calcLineTotal($itemData);
                $subTotal += $lineTotal;
                SalesQuotationItem::create([
                    'quot_id'       => $quot->id,
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

            $quot->sub_total    = round($subTotal, 2);
            $quot->amount_total = round($subTotal + ($validated['shipping_charge'] ?? 0), 2);
            $quot->save();

            return ApiResponse::created($quot->load('items'), 'Sales quotation created');
        });
    }

    public function show(int $id): JsonResponse
    {
        $quot = SalesQuotation::with(['items', 'customer'])->find($id);
        if (! $quot) return ApiResponse::notFound('Sales quotation not found');
        return ApiResponse::success($quot, 'Sales quotation retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $quot = SalesQuotation::find($id);
        if (! $quot) return ApiResponse::notFound('Sales quotation not found');

        $validated = $request->validate([
            'quotation_date'      => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'valid_until'         => 'nullable|date',
            'payment_terms'       => 'nullable|integer',
            'price_list_id'       => 'nullable|integer',
            'shipping_charge'     => 'nullable|numeric|min:0',
            'dimension_id'        => 'nullable|integer',
            'dimension2_id'       => 'nullable|integer',
            'location_id'         => 'nullable|integer',
            'vehicle'             => 'nullable|string|max:50',
            'shift'               => 'nullable|string|max:20',
            'deliver_to'          => 'nullable|string|max:100',
            'address'             => 'nullable|string',
            'contact_phone'       => 'nullable|string|max:30',
            'customer_ref'        => 'nullable|string|max:60',
            'comments'            => 'nullable|string',
            'shipping_company_id' => 'nullable|integer',
            'branch_id'           => 'nullable|integer',
        ]);

        $quot->update($validated);
        $this->recalcTotals($quot);

        return ApiResponse::updated($quot->load('items'), 'Sales quotation updated');
    }

    public function place(int $id): JsonResponse
    {
        $quot = SalesQuotation::find($id);
        if (! $quot) return ApiResponse::notFound('Sales quotation not found');
        if ($quot->status !== 'draft') return ApiResponse::error('Quotation is not in draft status', 422);

        $quot->update(['status' => 'placed']);
        return ApiResponse::success($quot, 'Sales quotation placed');
    }

    public function cancel(int $id): JsonResponse
    {
        $quot = SalesQuotation::find($id);
        if (! $quot) return ApiResponse::notFound('Sales quotation not found');
        if (in_array($quot->status, ['converted'])) return ApiResponse::error('Cannot cancel a converted quotation', 422);

        $quot->update(['status' => 'cancelled']);
        return ApiResponse::success($quot, 'Sales quotation cancelled');
    }

    public function addItem(Request $request, int $id): JsonResponse
    {
        $quot = SalesQuotation::find($id);
        if (! $quot) return ApiResponse::notFound('Sales quotation not found');

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
        $item = SalesQuotationItem::create([
            'quot_id'       => $quot->id,
            'stock_id'      => $validated['stock_id'],
            'description'   => $validated['description'],
            'qty'           => $validated['qty'],
            'unit'          => $validated['unit'] ?? null,
            'price'         => $validated['price'],
            'standard_cost' => $validated['standard_cost'] ?? 0,
            'discount_pct'  => $validated['discount_pct'] ?? 0,
            'line_total'    => $lineTotal,
        ]);

        $this->recalcTotals($quot);
        return ApiResponse::created($item, 'Item added');
    }

    public function updateItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $item = SalesQuotationItem::where('quot_id', $id)->where('id', $itemId)->first();
        if (! $item) return ApiResponse::notFound('Item not found');

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

        $quot = SalesQuotation::find($id);
        if ($quot) $this->recalcTotals($quot);

        return ApiResponse::updated($item, 'Item updated');
    }

    public function removeItem(int $id, int $itemId): JsonResponse
    {
        $item = SalesQuotationItem::where('quot_id', $id)->where('id', $itemId)->first();
        if (! $item) return ApiResponse::notFound('Item not found');

        $item->delete();

        $quot = SalesQuotation::find($id);
        if ($quot) $this->recalcTotals($quot);

        return ApiResponse::deleted('Item removed');
    }

    public function convertToOrder(int $id): JsonResponse
    {
        $quot = SalesQuotation::with('items')->find($id);
        if (! $quot) return ApiResponse::notFound('Sales quotation not found');
        if ($quot->status !== 'placed') return ApiResponse::error('Only placed quotations can be converted', 422);

        return DB::transaction(function () use ($quot) {
            $order = SalesOrder::create([
                'so_no'              => SalesOrder::nextSoNo(),
                'debtor_no'          => $quot->debtor_no,
                'branch_id'          => $quot->branch_id,
                'order_date'         => now()->toDateString(),
                'delivery_date'      => $quot->valid_until?->toDateString(),
                'payment_terms'      => $quot->payment_terms,
                'price_list_id'      => $quot->price_list_id,
                'shipping_charge'    => $quot->shipping_charge,
                'sub_total'          => $quot->sub_total,
                'amount_total'       => $quot->amount_total,
                'status'             => 'placed',
                'dimension_id'       => $quot->dimension_id,
                'dimension2_id'      => $quot->dimension2_id,
                'location_id'        => $quot->location_id,
                'vehicle'            => $quot->vehicle,
                'shift'              => $quot->shift,
                'deliver_to'         => $quot->deliver_to,
                'address'            => $quot->address,
                'contact_phone'      => $quot->contact_phone,
                'customer_ref'       => $quot->customer_ref,
                'comments'           => $quot->comments,
                'shipping_company_id'=> $quot->shipping_company_id,
                'created_by'         => auth()->user()?->user_id ?? 'system',
            ]);

            foreach ($quot->items as $qi) {
                SalesOrderItem::create([
                    'so_id'         => $order->id,
                    'stock_id'      => $qi->stock_id,
                    'description'   => $qi->description,
                    'qty'           => $qi->qty,
                    'unit'          => $qi->unit,
                    'price'         => $qi->price,
                    'standard_cost' => $qi->standard_cost,
                    'discount_pct'  => $qi->discount_pct,
                    'line_total'    => $qi->line_total,
                ]);
            }

            $quot->update(['status' => 'converted', 'so_id' => $order->id]);

            return ApiResponse::created([
                'quotation' => $quot->fresh(),
                'order'     => $order->load('items'),
            ], "Quotation converted to Sales Order {$order->so_no}");
        });
    }
}
