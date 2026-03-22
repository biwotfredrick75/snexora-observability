<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    private function nextPoNo(string $type): string
    {
        $prefix = match($type) {
            'grn'     => 'GRN',
            'invoice' => 'INV',
            default   => 'PO',
        };
        $last = PurchaseOrder::where('type', $type)->lockForUpdate()->max('id') ?? 0;
        return $prefix . str_pad($last + 1, 4, '0', STR_PAD_LEFT) . '/' . date('Y');
    }

    public function index(Request $request): JsonResponse
    {
        $type = $request->type ?? 'po';
        $q = PurchaseOrder::with(['supplier:supplierId,supplierName,supplierReference'])
            ->where('type', $type);

        if ($request->status)      $q->where('status', $request->status);
        if ($request->supplier_id) $q->where('supplier_id', $request->supplier_id);
        if ($request->location_id) $q->where('location_id', $request->location_id);
        if ($request->from)        $q->where('order_date', '>=', $request->from);
        if ($request->to)          $q->where('order_date', '<=', $request->to);
        if ($request->po_no)       $q->where('po_no', 'like', "%{$request->po_no}%");

        $pos = $q->orderByDesc('id')->get();
        return ApiResponse::success($pos, 'Orders retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->type ?? 'po';

        $request->validate([
            'supplier_id' => 'required|integer',
            'order_date'  => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'location_id' => 'required|string|max:30',
            'items'       => 'required|array|min:1',
            'items.*.stock_id' => 'required|string|max:20',
            'items.*.qty'      => 'required|numeric|min:0.0001',
            'items.*.price_before_tax' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $type) {
            $po = PurchaseOrder::create([
                'po_no'               => 'TEMP-' . uniqid(),
                'type'                => $type,
                'supplier_id'         => $request->supplier_id,
                'reference'           => $request->reference ?? '',
                'supplier_reference'  => $request->supplier_reference ?? '',
                'order_date'          => $request->order_date,
                'delivery_date'       => $request->delivery_date,
                'due_date'            => $request->due_date,
                'location_id'         => $request->location_id,
                'receive_into'        => $request->receive_into ?? '',
                'payment_terms'       => $request->payment_terms ?? 'cash',
                'currency'            => $request->currency ?? 'KES',
                'exchange_rate'       => $request->exchange_rate ?? 1,
                'status'              => 'draft',
                'raised_by'           => auth()->user()?->user_id ?? 'system',
                'customer_memo'       => $request->customer_memo ?? '',
                'internal_memo'       => $request->internal_memo ?? '',
            ]);

            $po->update(['po_no' => $this->nextPoNo($type)]);

            $subTotal = 0;
            foreach ($request->items as $item) {
                $price    = (float)($item['price_before_tax'] ?? 0);
                $discount = (float)($item['discount_amt'] ?? 0);
                $qty      = (float)$item['qty'];
                $lineTotal = ($price * $qty) - $discount;
                $subTotal += $lineTotal;

                PurchaseOrderItem::create([
                    'po_id'                 => $po->id,
                    'stock_id'              => $item['stock_id'],
                    'description'           => $item['description'] ?? '',
                    'qty'                   => $qty,
                    'unit'                  => $item['unit'] ?? '',
                    'required_delivery_date'=> $item['required_delivery_date'] ?? null,
                    'price_before_tax'      => $price,
                    'discount_amt'          => $discount,
                    'line_total'            => $lineTotal,
                ]);
            }

            $po->update(['sub_total' => $subTotal, 'amount_total' => $subTotal]);
            $po->load(['items', 'supplier:supplierId,supplierName,supplierReference']);

            return ApiResponse::created($po, ucfirst($type) . ' created');
        });
    }

    public function show(int $id): JsonResponse
    {
        $po = PurchaseOrder::with(['items', 'supplier:supplierId,supplierName,supplierReference,currencyCode,paymentTerms,creditLimit'])
            ->findOrFail($id);
        return ApiResponse::success($po, 'Order retrieved');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);

        if (!in_array($po->status, ['draft', 'rejected'])) {
            return ApiResponse::error('Only draft or rejected orders can be edited', 422);
        }

        $request->validate([
            'supplier_id' => 'required|integer',
            'order_date'  => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'location_id' => 'required|string|max:30',
            'items'       => 'required|array|min:1',
        ]);

        return DB::transaction(function () use ($request, $po) {
            $po->update([
                'supplier_id'        => $request->supplier_id,
                'reference'          => $request->reference ?? $po->reference,
                'supplier_reference' => $request->supplier_reference ?? $po->supplier_reference,
                'order_date'         => $request->order_date,
                'delivery_date'      => $request->delivery_date,
                'due_date'           => $request->due_date,
                'location_id'        => $request->location_id,
                'receive_into'       => $request->receive_into ?? $po->receive_into,
                'payment_terms'      => $request->payment_terms ?? $po->payment_terms,
                'customer_memo'      => $request->customer_memo ?? $po->customer_memo,
                'internal_memo'      => $request->internal_memo ?? $po->internal_memo,
            ]);

            $po->items()->delete();

            $subTotal = 0;
            foreach ($request->items as $item) {
                $price     = (float)($item['price_before_tax'] ?? 0);
                $discount  = (float)($item['discount_amt'] ?? 0);
                $qty       = (float)$item['qty'];
                $lineTotal = ($price * $qty) - $discount;
                $subTotal += $lineTotal;

                PurchaseOrderItem::create([
                    'po_id'                 => $po->id,
                    'stock_id'              => $item['stock_id'],
                    'description'           => $item['description'] ?? '',
                    'qty'                   => $qty,
                    'unit'                  => $item['unit'] ?? '',
                    'required_delivery_date'=> $item['required_delivery_date'] ?? null,
                    'price_before_tax'      => $price,
                    'discount_amt'          => $discount,
                    'line_total'            => $lineTotal,
                ]);
            }

            $po->update(['sub_total' => $subTotal, 'amount_total' => $subTotal]);
            $po->load(['items', 'supplier:supplierId,supplierName,supplierReference']);

            return ApiResponse::updated($po, 'Order updated');
        });
    }

    public function destroy(int $id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'draft') return ApiResponse::error('Only draft orders can be deleted', 422);
        $po->delete();
        return ApiResponse::deleted('Order deleted');
    }

    // ── Approval actions ──────────────────────────────────────────────────────

    public function submit(int $id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'draft') return ApiResponse::error('Only draft orders can be submitted', 422);
        $po->update(['status' => 'submitted']);
        return ApiResponse::success($po, 'Order submitted');
    }

    public function hodApprove(int $id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'submitted') return ApiResponse::error('Order must be submitted first', 422);
        $po->update([
            'status'            => 'hod_approved',
            'hod_approval_by'   => auth()->user()?->user_id ?? 'system',
            'hod_approval_date' => now()->toDateString(),
        ]);
        return ApiResponse::success($po, 'HOD approved');
    }

    public function financeApprove(int $id): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);
        if ($po->status !== 'hod_approved') return ApiResponse::error('Order must be HOD approved first', 422);
        $po->update([
            'status'                => 'finance_approved',
            'finance_approval_by'   => auth()->user()?->user_id ?? 'system',
            'finance_approval_date' => now()->toDateString(),
        ]);
        return ApiResponse::success($po, 'Finance approved');
    }

    public function ceoApprove(int $id): JsonResponse
    {
        $po = PurchaseOrder::with('items')->findOrFail($id);
        if ($po->status !== 'finance_approved') return ApiResponse::error('Order must be Finance approved first', 422);

        return DB::transaction(function () use ($po) {
            $approver  = auth()->user()?->user_id ?? 'system';
            $approvals = [
                'ceo_approval_by'   => $approver,
                'ceo_approval_date' => now()->toDateString(),
            ];

            // For GRN type: post stock movements + GL entries on CEO approval
            if ($po->type === 'grn') {
                $this->postGrnReceipt($po, $approver);
                $po->update(array_merge($approvals, ['status' => 'received']));
                return ApiResponse::success($po->fresh(), 'GRN approved and goods received');
            }

            $po->update(array_merge($approvals, ['status' => 'ceo_approved']));
            return ApiResponse::success($po->fresh(), 'CEO/Operations approved');
        });
    }

    private function postGrnReceipt(PurchaseOrder $po, string $approver): void
    {
        $locCode   = $po->receive_into ?: $po->location_id;
        $tranDate  = $po->delivery_date ?? now()->toDateString();
        $glSetting = GlSetting::first();
        $grnClearing = $glSetting?->grn_clearing_account ?: 'GRN-CLEARING';

        foreach ($po->items as $item) {
            $qty  = (float) $item->qty;
            $cost = (float) $item->price_before_tax;
            $lineAmount = round($qty * $cost, 4);

            // Resolve inventory GL account from item master
            $itemRecord = DB::table('items')
                ->where('stock_id', $item->stock_id)
                ->select('inventory_account', 'dimension_id', 'dimension2_id')
                ->first();

            $inventoryAccount = $itemRecord?->inventory_account ?: ($glSetting?->items_inventory_account ?: 'INVENTORY');

            // ── Stock movement: goods in (positive qty) ──────────────────────
            StockMovement::create([
                'trans_no'      => $po->id,
                'stock_id'      => $item->stock_id,
                'type'          => StockMovement::TYPE_GRN,
                'loc_code'      => $locCode,
                'tran_date'     => $tranDate,
                'date_moved'    => $tranDate,
                'qty'           => $qty,
                'price'         => $cost,
                'standard_cost' => $cost,
                'reference'     => $po->po_no,
                'comments'      => $po->internal_memo ?: null,
                'user_name'     => $approver,
                'approved'      => 1,
            ]);

            if ($lineAmount == 0) continue;

            // ── GL: DR Inventory ─────────────────────────────────────────────
            GldTransaction::create([
                'trans_no'     => $po->id,
                'type'         => StockMovement::TYPE_GRN,
                'tran_date'    => $tranDate,
                'account_code' => $inventoryAccount,
                'reference'    => $po->po_no,
                'narration'    => "GRN — {$item->description} ({$po->po_no})",
                'amount'       => $lineAmount,        // positive = debit
                'created_by'   => $approver,
                'dimension_id' => $itemRecord?->dimension_id ?? null,
                'dimension2_id'=> $itemRecord?->dimension2_id ?? null,
            ]);

            // ── GL: CR GRN Clearing ──────────────────────────────────────────
            GldTransaction::create([
                'trans_no'     => $po->id,
                'type'         => StockMovement::TYPE_GRN,
                'tran_date'    => $tranDate,
                'account_code' => $grnClearing,
                'reference'    => $po->po_no,
                'narration'    => "GRN clearing — {$item->description} ({$po->po_no})",
                'amount'       => -$lineAmount,       // negative = credit
                'created_by'   => $approver,
                'dimension_id' => $itemRecord?->dimension_id ?? null,
                'dimension2_id'=> $itemRecord?->dimension2_id ?? null,
            ]);
        }
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        $po = PurchaseOrder::findOrFail($id);
        if (!in_array($po->status, ['submitted', 'hod_approved', 'finance_approved'])) {
            return ApiResponse::error('Order cannot be rejected at this stage', 422);
        }
        $po->update([
            'status'       => 'rejected',
            'internal_memo'=> ($request->reason ?? '') . "\n" . $po->internal_memo,
        ]);
        return ApiResponse::success($po, 'Order rejected');
    }
}
