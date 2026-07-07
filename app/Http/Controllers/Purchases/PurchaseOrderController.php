<?php

namespace App\Http\Controllers\Purchases;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CompanyPreference;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementService;
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

        $pos = $q->orderByDesc('id')->limit(500)->get();
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
                'source_grn_id'       => $request->source_grn_id ?? null,
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
                    'grn_item_id'           => $item['grn_item_id'] ?? null,
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
        $po = PurchaseOrder::with([
            'items',
            'supplier:supplierId,supplierName,supplierReference,currencyCode,paymentTerms,creditLimit',
            'sourceGrn:id,po_no,order_date',
        ])->findOrFail($id);
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
                    'grn_item_id'           => $item['grn_item_id'] ?? null,
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
        $po = PurchaseOrder::with('items')->findOrFail($id);
        if ($po->status !== 'draft') return ApiResponse::error('Only draft orders can be submitted', 422);

        $prefs = CompanyPreference::first();
        $noApprovals = !($prefs?->po_requires_hod_approval)
                    && !($prefs?->po_requires_finance_approval)
                    && !($prefs?->po_requires_ceo_approval);

        if ($noApprovals) {
            return DB::transaction(function () use ($po) {
                $approver = auth()->user()?->user_id ?? 'system';

                if ($po->type === 'grn') {
                    $this->postGrnReceipt($po, $approver);
                    $po->update(['status' => 'received']);
                    return ApiResponse::success($po->fresh(['items', 'supplier:supplierId,supplierName,supplierReference']), 'GRN posted — stock & GL updated');
                }

                if ($po->type === 'invoice') {
                    $this->postDirectInvoice($po, $approver);
                    $po->update(['status' => 'received']);
                    return ApiResponse::success($po->fresh(['items', 'supplier:supplierId,supplierName,supplierReference']), 'Invoice approved — stock & GL posted');
                }

                $po->update(['status' => 'ceo_approved']);
                return ApiResponse::success($po->fresh(['items', 'supplier:supplierId,supplierName,supplierReference']), 'Order approved — no approvals configured');
            });
        }

        $po->update(['status' => 'submitted']);
        return ApiResponse::success($po, 'Order submitted for approval');
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

            if ($po->type === 'grn') {
                $this->postGrnReceipt($po, $approver);
                $po->update(array_merge($approvals, ['status' => 'received']));
                $this->broadcastDashboard('purchases', 'grn_received', $po);
                return ApiResponse::success($po->fresh(), 'GRN approved and goods received');
            }

            if ($po->type === 'invoice') {
                $this->postDirectInvoice($po, $approver);
                $po->update(array_merge($approvals, ['status' => 'received']));
                $this->broadcastDashboard('purchases', 'invoice_received', $po);
                return ApiResponse::success($po->fresh(), 'Invoice approved — stock & GL posted');
            }

            $po->update(array_merge($approvals, ['status' => 'ceo_approved']));
            $this->broadcastDashboard('purchases', 'po_approved', $po);
            return ApiResponse::success($po->fresh(), 'CEO/Operations approved');
        });
    }

    // ── Receive goods for standard PO (type='po') after CEO approval ─────────
    public function receiveGoods(int $id): JsonResponse
    {
        $po = PurchaseOrder::with('items')->findOrFail($id);
        if ($po->type !== 'po') return ApiResponse::error('Only standard purchase orders can use this receive flow', 422);
        if ($po->status !== 'ceo_approved') return ApiResponse::error('Order must be CEO approved before receiving goods', 422);

        return DB::transaction(function () use ($po) {
            $approver = auth()->user()?->user_id ?? 'system';
            $this->postGrnReceipt($po, $approver);
            $po->update(['status' => 'received']);
            $this->broadcastDashboard('purchases', 'grn_received', $po);
            return ApiResponse::success($po->fresh(), 'Goods received — stock & GL posted');
        });
    }

    // Mirrors the Sales controllers' broadcast pattern: never let a Reverb
    // hiccup turn a successful purchase posting into an apparent request failure.
    private function broadcastDashboard(string $scope, string $action, PurchaseOrder $po): void
    {
        try {
            broadcast(new DashboardEvent($scope, $action, [
                'po_no'  => $po->po_no,
                'amount' => (float) $po->amount_total,
            ]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
        }
    }

    // ── GL view for purchase orders (GRN / Invoice) ───────────────────────────
    public function glTransactions(int $id): JsonResponse
    {
        $po = PurchaseOrder::with(['items', 'supplier:supplierId,supplierName'])->findOrFail($id);

        $glType = $po->type === 'invoice' ? 25 : StockMovement::TYPE_GRN; // 25 = direct invoice GL type

        $glEntries = DB::table('gld_transactions as g')
            ->leftJoin('gl_accounts as cm', 'cm.code', '=', 'g.account_code')
            ->where('g.reference', $po->po_no)
            ->where('g.type', $glType)
            ->select(
                'g.account_code',
                DB::raw('COALESCE(cm.name, g.account_code) AS account_name'),
                'g.narration as memo',
                'g.tran_date',
                'g.reference',
                DB::raw('CASE WHEN g.amount >= 0 THEN g.amount ELSE 0 END as debit'),
                DB::raw('CASE WHEN g.amount < 0 THEN ABS(g.amount) ELSE 0 END as credit'),
                'g.dimension_id',
                'g.dimension2_id',
            )
            ->orderBy('g.id')
            ->get();

        $company = DB::table('company_preferences')->first();

        return ApiResponse::success([
            'order'      => [
                'id'                 => $po->id,
                'po_no'              => $po->po_no,
                'reference'          => $po->reference,
                'supplier_reference' => $po->supplier_reference,
                'order_date'         => $po->order_date,
                'supplier'           => $po->supplier?->supplierName,
            ],
            'gl_entries' => $glEntries,
            'company'    => $company ? [
                'name'    => $company->name,
                'phone'   => $company->phone,
                'address' => $company->address,
                'email'   => $company->email,
            ] : [],
        ], 'GL transactions');
    }

    private function postGrnReceipt(PurchaseOrder $po, string $approver): void
    {
        $locCode   = $po->receive_into ?: $po->location_id;
        $tranDate  = $po->delivery_date ?? now()->toDateString();
        $glSetting = GlSetting::first();
        $grnClearing = $glSetting?->grn_clearing_account ?: 'GRN-CLEARING';
        $discountAccount = $glSetting?->purchase_discount_account ?: '401037';

        foreach ($po->items as $idx => $item) {
            $qty  = (float) $item->qty;
            $cost = (float) $item->price_before_tax;
            $discount = round((float) $item->discount_amt, 4);
            $grossAmount = round($qty * $cost, 4);
            $lineAmount  = round($grossAmount - $discount, 4);

            // Batch number uniquely identifies this GRN line: {po_no}-{1-based line}
            $batchNo = substr($po->po_no . '-' . ($idx + 1), 0, 50);

            // Resolve inventory GL account from item master
            $itemRecord = DB::table('items')
                ->where('stock_id', $item->stock_id)
                ->select('inventory_account', 'dimension_id', 'dimension2_id')
                ->first();

            $inventoryAccount = $itemRecord?->inventory_account ?: ($glSetting?->items_inventory_account ?: 'INVENTORY');

            // ── Stock movement: goods in (positive qty) with batch stamp ─────
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
                'batch_no'      => $batchNo,
                'batch_new'     => 1,
            ]);

            if ($grossAmount == 0) continue;

            // ── GL: DR Inventory (gross, before discount) ────────────────────
            GldTransaction::create([
                'trans_no'     => $po->id,
                'type'         => StockMovement::TYPE_GRN,
                'tran_date'    => $tranDate,
                'account_code' => $inventoryAccount,
                'reference'    => $po->po_no,
                'narration'    => "GRN — {$item->description} ({$po->po_no})",
                'amount'       => $grossAmount,        // positive = debit
                'created_by'   => $approver,
                'dimension_id' => $itemRecord?->dimension_id ?? null,
                'dimension2_id'=> $itemRecord?->dimension2_id ?? null,
            ]);

            // ── GL: CR Purchase Discount ──────────────────────────────────────
            if ($discount != 0) {
                GldTransaction::create([
                    'trans_no'     => $po->id,
                    'type'         => StockMovement::TYPE_GRN,
                    'tran_date'    => $tranDate,
                    'account_code' => $discountAccount,
                    'reference'    => $po->po_no,
                    'narration'    => "Purchase discount — {$item->description} ({$po->po_no})",
                    'amount'       => -$discount,      // negative = credit
                    'created_by'   => $approver,
                    'dimension_id' => $itemRecord?->dimension_id ?? null,
                    'dimension2_id'=> $itemRecord?->dimension2_id ?? null,
                ]);
            }

            // ── GL: CR GRN Clearing (net payable) ─────────────────────────────
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

    private function postDirectInvoice(PurchaseOrder $po, string $approver): void
    {
        $locCode   = $po->receive_into ?: $po->location_id;
        $tranDate  = $po->delivery_date ?? $po->order_date ?? now()->toDateString();
        $glSetting = GlSetting::first();
        $apAccount = $glSetting?->supplier_payable_account ?: '201010';
        $glType    = 25; // direct supplier invoice

        foreach ($po->items as $idx => $item) {
            $qty        = (float) $item->qty;
            $cost       = (float) $item->price_before_tax;
            $lineAmount = round($qty * $cost - (float) $item->discount_amt, 4);

            $batchNo = substr($po->po_no . '-' . ($idx + 1), 0, 50);

            $itemRecord = DB::table('items')
                ->where('stock_id', $item->stock_id)
                ->select('inventory_account', 'dimension_id', 'dimension2_id')
                ->first();

            $inventoryAccount = $itemRecord?->inventory_account ?: ($glSetting?->items_inventory_account ?: 'INVENTORY');

            // Stock movement: goods in
            StockMovement::create([
                'trans_no'      => $po->id,
                'stock_id'      => $item->stock_id,
                'type'          => $glType,
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
                'batch_no'      => $batchNo,
                'batch_new'     => 1,
            ]);

            if ($lineAmount == 0) continue;

            // GL: DR Inventory account
            GldTransaction::create([
                'trans_no'      => $po->id,
                'type'          => $glType,
                'tran_date'     => $tranDate,
                'account_code'  => $inventoryAccount,
                'reference'     => $po->po_no,
                'narration'     => "Direct Invoice — {$item->description} ({$po->po_no})",
                'amount'        => $lineAmount,
                'created_by'    => $approver,
                'dimension_id'  => $itemRecord?->dimension_id  ?? null,
                'dimension2_id' => $itemRecord?->dimension2_id ?? null,
            ]);

            // GL: CR Accounts Payable
            GldTransaction::create([
                'trans_no'      => $po->id,
                'type'          => $glType,
                'tran_date'     => $tranDate,
                'account_code'  => $apAccount,
                'reference'     => $po->po_no,
                'narration'     => "AP — {$po->supplier?->supplierName} ({$po->po_no})",
                'amount'        => -$lineAmount,
                'created_by'    => $approver,
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

    /**
     * GET /purchases/purchase-orders/grn-items-for-invoice?supplier_id=
     *
     * Returns GRN items received but not yet fully invoiced for a supplier.
     * Used by the supplier invoice entry form to populate the items table.
     */
    public function grnItemsForInvoice(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => 'required|integer']);

        $supplierId = $request->supplier_id;

        // Find received GRNs for this supplier (direct GRNs and standard POs alike)
        $grnIds = DB::table('purchase_orders')
            ->whereIn('type', ['grn', 'po'])
            ->where('supplier_id', $supplierId)
            ->where('status', 'received')
            ->pluck('id');

        if ($grnIds->isEmpty()) {
            return ApiResponse::success([], 'No received GRN items found');
        }

        // Get all GRN items
        $grnItems = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->whereIn('poi.po_id', $grnIds)
            ->select(
                'poi.id as grn_item_id',
                'poi.po_id as grn_po_id',
                'po.po_no as grn_no',
                'poi.stock_id',
                'poi.description',
                DB::raw('CAST(po.delivery_date AS DATE) as received_on'),
                DB::raw('poi.qty as qty_received'),
                'poi.price_before_tax',
                'poi.unit'
            )
            ->get();

        // Get quantities already invoiced — primary: via grn_item_id link
        $invoicedByGrnItem = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->where('po.type', 'invoice')
            ->whereIn('po.status', ['submitted', 'hod_approved', 'finance_approved', 'received', 'ceo_approved'])
            ->whereNotNull('poi.grn_item_id')
            ->whereIn('poi.grn_item_id', $grnItems->pluck('grn_item_id'))
            ->select('poi.grn_item_id', DB::raw('SUM(poi.qty) as qty_invoiced'))
            ->groupBy('poi.grn_item_id')
            ->get()
            ->keyBy('grn_item_id');

        // Fallback: via source_grn_id on the invoice (items without grn_item_id)
        $invoicedBySourceGrn = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->where('po.type', 'invoice')
            ->whereIn('po.status', ['submitted', 'hod_approved', 'finance_approved', 'received', 'ceo_approved'])
            ->whereNull('poi.grn_item_id')
            ->whereIn('po.source_grn_id', $grnIds)
            ->select('po.source_grn_id', 'poi.stock_id', DB::raw('SUM(poi.qty) as qty_invoiced'))
            ->groupBy('po.source_grn_id', 'poi.stock_id')
            ->get()
            ->groupBy('source_grn_id');

        $result = $grnItems->map(function ($item) use ($invoicedByGrnItem, $invoicedBySourceGrn) {
            $invoiced = (float)($invoicedByGrnItem[$item->grn_item_id]->qty_invoiced ?? 0);
            // Add fallback from source_grn_id match
            if ($invoiced == 0 && $invoicedBySourceGrn->has($item->grn_po_id)) {
                $match = $invoicedBySourceGrn[$item->grn_po_id]->firstWhere('stock_id', $item->stock_id);
                $invoiced = (float)($match->qty_invoiced ?? 0);
            }
            $yetToInvoice = max(0, (float)$item->qty_received - $invoiced);

            return [
                'grn_item_id'     => $item->grn_item_id,
                'grn_po_id'       => $item->grn_po_id,
                'grn_no'          => $item->grn_no,
                'stock_id'        => $item->stock_id,
                'description'     => $item->description,
                'received_on'     => $item->received_on,
                'qty_received'    => (float)$item->qty_received,
                'qty_invoiced'    => $invoiced,
                'qty_yet_to_invoice' => $yetToInvoice,
                'price_before_tax'=> (float)$item->price_before_tax,
                'unit'            => $item->unit ?? '',
                'line_total'      => round($yetToInvoice * (float)$item->price_before_tax, 4),
            ];
        })->filter(fn($r) => $r['qty_yet_to_invoice'] > 0)->values();

        return ApiResponse::success($result, 'GRN items retrieved');
    }
}
