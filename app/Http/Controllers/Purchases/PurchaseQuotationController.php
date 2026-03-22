<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PurchaseQuotation;
use App\Models\PurchaseQuotationItem;
use App\Models\PurchaseQuotationSupplier;
use App\Models\PurchaseQuotationItemPrice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseQuotationController extends Controller
{
    private function nextQuotationNo(): string
    {
        $last = PurchaseQuotation::lockForUpdate()->max('id') ?? 0;
        return 'RFQ' . str_pad($last + 1, 4, '0', STR_PAD_LEFT) . '/' . date('Y');
    }

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $q = PurchaseQuotation::with(['suppliers.supplier:supplierId,supplierName,supplierReference']);

        if ($request->status)   $q->where('status', $request->status);
        if ($request->pr_id)    $q->where('pr_id', $request->pr_id);
        if ($request->from)     $q->where('quotation_date', '>=', $request->from);
        if ($request->to)       $q->where('quotation_date', '<=', $request->to);
        if ($request->rfq_no)   $q->where('quotation_no', 'like', "%{$request->rfq_no}%");

        return ApiResponse::success($q->orderByDesc('id')->get(), 'Quotations retrieved');
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'quotation_date'  => 'required|date',
            'location_id'     => 'required|string|max:30',
            'items'           => 'required|array|min:1',
            'items.*.stock_id'     => 'required|string|max:20',
            'items.*.qty_required' => 'required|numeric|min:0.0001',
        ]);

        return DB::transaction(function () use ($request) {
            $q = PurchaseQuotation::create([
                'quotation_no'   => 'TEMP-' . uniqid(),
                'pr_id'          => $request->pr_id,
                'reference'      => $request->reference ?? '',
                'quotation_date' => $request->quotation_date,
                'due_date'       => $request->due_date,
                'location_id'    => $request->location_id,
                'status'         => 'draft',
                'raised_by'      => auth()->user()?->user_id ?? 'system',
                'notes'          => $request->notes ?? '',
            ]);

            $q->update(['quotation_no' => $this->nextQuotationNo()]);

            foreach ($request->items as $item) {
                PurchaseQuotationItem::create([
                    'quotation_id' => $q->id,
                    'stock_id'     => $item['stock_id'],
                    'description'  => $item['description'] ?? '',
                    'qty_required' => $item['qty_required'],
                    'unit'         => $item['unit'] ?? '',
                ]);
            }

            $q->load(['items', 'suppliers']);
            return ApiResponse::created($q, 'Quotation request created');
        });
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $q = PurchaseQuotation::with([
            'items',
            'suppliers.supplier:supplierId,supplierName,supplierReference',
            'suppliers.prices',
            'pr:id,pr_no,branch',
        ])->findOrFail($id);

        return ApiResponse::success($q, 'Quotation retrieved');
    }

    // ── Dispatch — add suppliers and mark dispatched ──────────────────────────

    public function dispatch(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'supplier_ids'   => 'required|array|min:1',
            'supplier_ids.*' => 'required|integer',
        ]);

        $q = PurchaseQuotation::findOrFail($id);

        if (!in_array($q->status, ['draft', 'dispatched'])) {
            return ApiResponse::error('Quotation cannot be dispatched at this stage', 422);
        }

        return DB::transaction(function () use ($q, $request) {
            foreach ($request->supplier_ids as $supplierId) {
                // avoid duplicates
                $q->suppliers()->firstOrCreate(
                    ['supplier_id' => $supplierId],
                    ['status' => 'pending', 'total_amount' => 0]
                );
            }
            $q->update(['status' => 'dispatched']);
            $q->load(['suppliers.supplier:supplierId,supplierName,supplierReference', 'items']);
            return ApiResponse::success($q, 'Quotation dispatched');
        });
    }

    // ── Receive — record a supplier's quoted prices ───────────────────────────

    public function receiveResponse(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'quotation_supplier_id' => 'required|integer',
            'prices'                => 'required|array|min:1',
            'prices.*.stock_id'     => 'required|string',
            'prices.*.unit_price'   => 'required|numeric|min:0',
        ]);

        $q = PurchaseQuotation::findOrFail($id);

        if (!in_array($q->status, ['dispatched', 'received'])) {
            return ApiResponse::error('Can only receive responses for dispatched quotations', 422);
        }

        return DB::transaction(function () use ($q, $request) {
            $qs = PurchaseQuotationSupplier::where('id', $request->quotation_supplier_id)
                ->where('quotation_id', $q->id)
                ->firstOrFail();

            // Replace prices for this supplier
            $qs->prices()->delete();

            $total = 0;
            foreach ($request->prices as $p) {
                $price    = (float)$p['unit_price'];
                $discount = (float)($p['discount_amt'] ?? 0);
                $qty      = (float)($p['qty'] ?? 1);
                $lineTotal = ($price * $qty) - $discount;
                $total    += $lineTotal;

                PurchaseQuotationItemPrice::create([
                    'quotation_supplier_id' => $qs->id,
                    'stock_id'              => $p['stock_id'],
                    'unit_price'            => $price,
                    'discount_amt'          => $discount,
                    'line_total'            => $lineTotal,
                ]);
            }

            $qs->update([
                'status'        => 'received',
                'received_date' => $request->received_date ?? now()->toDateString(),
                'total_amount'  => $total,
                'notes'         => $request->notes ?? $qs->notes,
            ]);

            // If all invited suppliers have responded, move to received
            $allReceived = $q->suppliers()->where('status', 'pending')->doesntExist();
            if ($allReceived) {
                $q->update(['status' => 'received']);
            }

            $q->load(['items', 'suppliers.supplier:supplierId,supplierName,supplierReference', 'suppliers.prices']);
            return ApiResponse::success($q, 'Response recorded');
        });
    }

    // ── Rank — select the winning supplier ────────────────────────────────────

    public function rank(int $id, Request $request): JsonResponse
    {
        $request->validate(['quotation_supplier_id' => 'required|integer']);

        $q = PurchaseQuotation::findOrFail($id);

        if (!in_array($q->status, ['received', 'ranked'])) {
            return ApiResponse::error('Quotation must have received responses before ranking', 422);
        }

        return DB::transaction(function () use ($q, $request) {
            // Clear previous selection
            $q->suppliers()->update(['status' => 'received']);

            $qs = PurchaseQuotationSupplier::where('id', $request->quotation_supplier_id)
                ->where('quotation_id', $q->id)
                ->firstOrFail();

            $qs->update(['status' => 'selected']);
            $q->update(['status' => 'ranked']);

            $q->load(['items', 'suppliers.supplier:supplierId,supplierName,supplierReference', 'suppliers.prices']);
            return ApiResponse::success($q, 'Supplier selected');
        });
    }

    // ── Convert — generate PO from ranked quotation ───────────────────────────

    public function convertToPo(int $id): JsonResponse
    {
        $q = PurchaseQuotation::with(['items', 'suppliers' => fn($s) => $s->where('status', 'selected'), 'suppliers.prices'])
            ->findOrFail($id);

        if ($q->status !== 'ranked') {
            return ApiResponse::error('Quotation must be ranked before converting to PO', 422);
        }

        $winner = $q->suppliers->first();
        if (!$winner) {
            return ApiResponse::error('No supplier selected', 422);
        }

        return DB::transaction(function () use ($q, $winner) {
            $po = PurchaseOrder::create([
                'po_no'              => 'TEMP-' . uniqid(),
                'type'               => 'po',
                'supplier_id'        => $winner->supplier_id,
                'reference'          => $q->quotation_no,
                'supplier_reference' => '',
                'order_date'         => now()->toDateString(),
                'delivery_date'      => $q->due_date,
                'due_date'           => $q->due_date,
                'location_id'        => $q->location_id,
                'receive_into'       => '',
                'payment_terms'      => 'cash',
                'currency'           => 'KES',
                'exchange_rate'      => 1,
                'status'             => 'draft',
                'raised_by'          => auth()->user()?->user_id ?? 'system',
                'customer_memo'      => '',
                'internal_memo'      => "Generated from {$q->quotation_no}",
                'sub_total'          => 0,
                'amount_total'       => 0,
            ]);

            // Generate PO number
            $last = PurchaseOrder::where('type', 'po')->lockForUpdate()->max('id') ?? 0;
            $po->update(['po_no' => 'PO' . str_pad($last, 4, '0', STR_PAD_LEFT) . '/' . date('Y')]);

            $subTotal = 0;
            foreach ($q->items as $item) {
                $price = $winner->prices->firstWhere('stock_id', $item->stock_id);
                $unitPrice = $price?->unit_price ?? 0;
                $discount  = $price?->discount_amt ?? 0;
                $lineTotal = ($unitPrice * $item->qty_required) - $discount;
                $subTotal += $lineTotal;

                PurchaseOrderItem::create([
                    'po_id'            => $po->id,
                    'stock_id'         => $item->stock_id,
                    'description'      => $item->description,
                    'qty'              => $item->qty_required,
                    'unit'             => $item->unit,
                    'price_before_tax' => $unitPrice,
                    'discount_amt'     => $discount,
                    'line_total'       => $lineTotal,
                ]);
            }

            $po->update(['sub_total' => $subTotal, 'amount_total' => $subTotal]);
            $q->update(['status' => 'converted']);

            $po->load(['items', 'supplier:supplierId,supplierName,supplierReference']);
            return ApiResponse::success($po, 'PO generated from quotation');
        });
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function destroy(int $id): JsonResponse
    {
        $q = PurchaseQuotation::findOrFail($id);
        if ($q->status !== 'draft') {
            return ApiResponse::error('Only draft quotations can be deleted', 422);
        }
        $q->delete();
        return ApiResponse::deleted('Quotation deleted');
    }
}
