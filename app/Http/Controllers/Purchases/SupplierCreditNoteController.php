<?php

namespace App\Http\Controllers\Purchases;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierCreditNote;
use App\Models\SupplierCreditNoteGlItem;
use App\Models\SupplierCreditNoteItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierCreditNoteController extends Controller
{
    /**
     * GET /purchases/supplier-credit-notes/form-data
     * Returns suppliers, dimensions, and GL accounts for dropdowns.
     */
    public function formData(): JsonResponse
    {
        $suppliers  = Supplier::select('supplierId', 'supplierName', 'paymentTerms', 'creditLimit')
            ->orderBy('supplierName')->get();

        $dimensions = DB::table('dimensions')->select('id', 'name')->orderBy('name')->get();

        $glAccounts = DB::table('gl_accounts')
            ->select('code as account_code', 'name as account_name')
            ->orderBy('account_code')
            ->get();

        return ApiResponse::success(compact('suppliers', 'dimensions', 'glAccounts'), 'Form data retrieved');
    }

    /**
     * GET /purchases/supplier-credit-notes/received-items
     * Returns GRN delivery items for a supplier within a date range
     * that have been received and can be credited.
     *
     * Query params: supplier_id, from, to
     */
    public function receivedItems(Request $request): JsonResponse
    {
        $request->validate([
            'supplier_id' => 'required|integer',
            'from'        => 'required|date',
            'to'          => 'required|date',
        ]);

        // Find GRN-type purchase orders for this supplier that are received
        $poIds = DB::table('purchase_orders')
            ->where('type', 'grn')
            ->where('supplier_id', $request->supplier_id)
            ->where('status', 'received')
            ->whereBetween('delivery_date', [$request->from, $request->to])
            ->pluck('id');

        if ($poIds->isEmpty()) {
            return ApiResponse::success([], 'No received items found');
        }

        // Get all items from those GRNs
        $items = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->whereIn('poi.po_id', $poIds)
            ->select(
                'poi.id',
                'poi.po_id',
                'po.po_no as grn_no',
                'po.po_no',
                'poi.stock_id',
                'poi.description',
                'po.delivery_date as received_on',
                DB::raw('poi.qty as qty_received'),
                DB::raw('poi.qty as qty_invoiced'),   // treat all received qty as invoiced
                'poi.price_before_tax',
                'poi.line_total'
            )
            ->get();

        // Subtract quantities already credited in posted credit notes
        $creditedQtys = DB::table('supplier_credit_note_items as sci')
            ->join('supplier_credit_notes as scn', 'scn.id', '=', 'sci.scn_id')
            ->where('scn.status', 'posted')
            ->whereIn('sci.po_id', $poIds)
            ->select('sci.po_id', 'sci.stock_id', DB::raw('SUM(sci.qty_to_credit) as credited'))
            ->groupBy('sci.po_id', 'sci.stock_id')
            ->get()
            ->keyBy(fn($r) => $r->po_id . '_' . $r->stock_id);

        $result = $items->map(function ($row) use ($creditedQtys) {
            $key        = $row->po_id . '_' . $row->stock_id;
            $credited   = (float)($creditedQtys[$key]->credited ?? 0);
            $yetToCredit = max(0, (float)$row->qty_received - $credited);
            return [
                'po_id'          => $row->po_id,
                'grn_no'         => $row->grn_no,
                'po_no'          => $row->po_no,
                'stock_id'       => $row->stock_id,
                'description'    => $row->description,
                'received_on'    => $row->received_on,
                'qty_received'   => (float)$row->qty_received,
                'qty_invoiced'   => (float)$row->qty_invoiced,
                'qty_to_credit'  => $yetToCredit,
                'price_before_tax'=> (float)$row->price_before_tax,
                'line_total'     => round($yetToCredit * (float)$row->price_before_tax, 4),
            ];
        })->filter(fn($r) => $r['qty_to_credit'] > 0)->values();

        return ApiResponse::success($result, 'Received items retrieved');
    }

    /**
     * GET /purchases/supplier-credit-notes
     */
    public function index(Request $request): JsonResponse
    {
        $q = SupplierCreditNote::with('supplier:supplierId,supplierName');

        if ($request->supplier_id) $q->where('supplier_id', $request->supplier_id);
        if ($request->status)      $q->where('status', $request->status);
        if ($request->from)        $q->where('date', '>=', $request->from);
        if ($request->to)          $q->where('date', '<=', $request->to);
        if ($request->scn_no)      $q->where('scn_no', 'like', "%{$request->scn_no}%");

        return ApiResponse::success($q->orderByDesc('id')->get(), 'Credit notes retrieved');
    }

    /**
     * GET /purchases/supplier-credit-notes/{id}/gl
     */
    public function glEntries(int $id): JsonResponse
    {
        $scn = SupplierCreditNote::with('supplier:supplierId,supplierName')->findOrFail($id);

        $glEntries = DB::table('gld_transactions as g')
            ->leftJoin('gl_accounts as cm', 'cm.code', '=', 'g.account_code')
            ->where('g.trans_no', $scn->id)
            ->where('g.reference', $scn->scn_no)
            ->select(
                'g.account_code',
                DB::raw('COALESCE(cm.name, g.account_code) AS account_name'),
                'g.narration as memo',
                'g.tran_date',
                'g.reference',
                DB::raw('CASE WHEN g.amount >= 0 THEN g.amount ELSE 0 END as debit'),
                DB::raw('CASE WHEN g.amount < 0 THEN ABS(g.amount) ELSE 0 END as credit'),
            )
            ->orderBy('g.id')
            ->get();

        $company = DB::table('company_preferences')->first();

        return ApiResponse::success([
            'scn'        => $scn,
            'gl_entries' => $glEntries,
            'company'    => $company ? [
                'name'    => $company->name,
                'phone'   => $company->phone,
                'address' => $company->address,
            ] : [],
        ], 'GL entries');
    }

    /**
     * GET /purchases/supplier-credit-notes/{id}
     */
    public function show(int $id): JsonResponse
    {
        $scn = SupplierCreditNote::with(['supplier:supplierId,supplierName', 'items', 'glItems'])
            ->findOrFail($id);
        return ApiResponse::success($scn, 'Credit note retrieved');
    }

    /**
     * POST /purchases/supplier-credit-notes
     * Creates and immediately posts the supplier credit note.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'supplier_id'  => 'required|integer|exists:suppliers,supplierId',
            'date'         => 'required|date',
            'items'        => 'nullable|array',
            'items.*.po_id'          => 'required_with:items|integer',
            'items.*.stock_id'       => 'required_with:items|string|max:20',
            'items.*.qty_to_credit'  => 'required_with:items|numeric|min:0.0001',
            'items.*.price_before_tax'=> 'required_with:items|numeric|min:0',
            'gl_items'     => 'nullable|array',
            'gl_items.*.account_code' => 'required_with:gl_items|string|max:20',
            'gl_items.*.amount'       => 'required_with:gl_items|numeric|min:0.0001',
        ]);

        $items   = $request->items   ?? [];
        $glItems = $request->gl_items ?? [];

        if (empty($items) && empty($glItems)) {
            return ApiResponse::error('Add at least one delivery item or GL entry', 422);
        }

        return DB::transaction(function () use ($request, $items, $glItems) {
            $user = auth()->user()?->user_id ?? 'system';

            // Calculate totals
            $itemsTotal = collect($items)->sum(fn($i) => round((float)$i['qty_to_credit'] * (float)$i['price_before_tax'], 4));
            $glTotal    = collect($glItems)->sum(fn($g) => (float)$g['amount']);
            $total      = $itemsTotal + $glTotal;

            // Create header (temp SCN no)
            $scn = SupplierCreditNote::create([
                'scn_no'            => 'TEMP-' . uniqid(),
                'supplier_id'       => $request->supplier_id,
                'date'              => $request->date,
                'due_date'          => $request->due_date,
                'reference'         => $request->reference ?? '',
                'source_invoice_no' => $request->source_invoice_no ?? '',
                'supplier_ref'      => $request->supplier_ref ?? '',
                'terms'             => $request->terms ?? '',
                'tax_group'         => $request->tax_group ?? '',
                'dimension_id'      => $request->dimension_id,
                'dimension2_id'     => $request->dimension2_id,
                'items_total'       => $itemsTotal,
                'gl_total'          => $glTotal,
                'total'             => $total,
                'memo'              => $request->memo ?? '',
                'status'            => 'posted',
                'raised_by'         => $user,
            ]);

            $scn->update(['scn_no' => SupplierCreditNote::nextScnNo()]);

            // Persist delivery items
            foreach ($items as $item) {
                $lineTotal = round((float)$item['qty_to_credit'] * (float)$item['price_before_tax'], 4);
                SupplierCreditNoteItem::create([
                    'scn_id'          => $scn->id,
                    'po_id'           => $item['po_id'],
                    'grn_no'          => $item['grn_no'] ?? '',
                    'stock_id'        => $item['stock_id'],
                    'description'     => $item['description'] ?? '',
                    'qty_received'    => $item['qty_received'] ?? $item['qty_to_credit'],
                    'qty_invoiced'    => $item['qty_invoiced'] ?? $item['qty_to_credit'],
                    'qty_to_credit'   => $item['qty_to_credit'],
                    'price_before_tax'=> $item['price_before_tax'],
                    'line_total'      => $lineTotal,
                ]);
            }

            // Persist GL items
            foreach ($glItems as $gl) {
                SupplierCreditNoteGlItem::create([
                    'scn_id'       => $scn->id,
                    'account_code' => $gl['account_code'],
                    'account_name' => $gl['account_name'] ?? '',
                    'dimension_id' => $gl['dimension_id'] ?? null,
                    'dimension2_id'=> $gl['dimension2_id'] ?? null,
                    'amount'       => $gl['amount'],
                    'memo'         => $gl['memo'] ?? '',
                ]);
            }

            // Post GL entries
            $this->postGlEntries($scn, $items, $glItems, $user);

            try {
                broadcast(new DashboardEvent('purchases', 'credit_note_posted', [
                    'scn_no' => $scn->scn_no,
                    'amount' => (float) $scn->total,
                ]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }

            $scn->load(['supplier:supplierId,supplierName', 'items', 'glItems']);
            return ApiResponse::created($scn, 'Supplier credit note posted successfully');
        });
    }

    /**
     * POST GL journal entries for the credit note.
     *
     * Delivery items:  DR Accounts Payable (reduce liability)
     *                  CR Inventory account (reduce asset — goods returned)
     *
     * GL items:        DR Accounts Payable
     *                  CR specified account
     */
    private function postGlEntries(SupplierCreditNote $scn, array $items, array $glItems, string $user): void
    {
        $glSetting      = GlSetting::first();
        $payableAccount = $glSetting?->payable_account ?: 'PAYABLES';
        $tranDate       = $scn->date->toDateString();
        $ref            = $scn->scn_no;

        // ── Delivery items ────────────────────────────────────────────────────
        foreach ($items as $item) {
            $amount = round((float)$item['qty_to_credit'] * (float)$item['price_before_tax'], 4);
            if ($amount == 0) continue;

            // Resolve inventory GL from item master
            $itemRecord       = DB::table('items')->where('stock_id', $item['stock_id'])->first();
            $inventoryAccount = $itemRecord?->inventory_account ?: ($glSetting?->items_inventory_account ?: 'INVENTORY');

            // DR Accounts Payable (reduces what we owe)
            GldTransaction::create([
                'trans_no'     => $scn->id,
                'type'         => StockMovement::TYPE_CREDIT_NOTE,
                'tran_date'    => $tranDate,
                'account_code' => $payableAccount,
                'reference'    => $ref,
                'narration'    => "SCN return — {$item['description']} ({$ref})",
                'amount'       => $amount,   // positive = debit
                'created_by'   => $user,
                'dimension_id' => $scn->dimension_id,
                'dimension2_id'=> $scn->dimension2_id,
            ]);

            // CR Inventory account
            GldTransaction::create([
                'trans_no'     => $scn->id,
                'type'         => StockMovement::TYPE_CREDIT_NOTE,
                'tran_date'    => $tranDate,
                'account_code' => $inventoryAccount,
                'reference'    => $ref,
                'narration'    => "SCN inventory return — {$item['description']} ({$ref})",
                'amount'       => -$amount,  // negative = credit
                'created_by'   => $user,
                'dimension_id' => $scn->dimension_id,
                'dimension2_id'=> $scn->dimension2_id,
            ]);

            // Reverse stock movement (negative qty = goods going back to supplier)
            StockMovement::create([
                'trans_no'      => $scn->id,
                'stock_id'      => $item['stock_id'],
                'type'          => StockMovement::TYPE_CREDIT_NOTE,
                'loc_code'      => $item['location_id'] ?? '',
                'tran_date'     => $tranDate,
                'date_moved'    => $tranDate,
                'qty'           => -(float)$item['qty_to_credit'],
                'price'         => (float)$item['price_before_tax'],
                'standard_cost' => (float)$item['price_before_tax'],
                'reference'     => $ref,
                'comments'      => $scn->memo ?: null,
                'user_name'     => $user,
                'approved'      => 1,
            ]);
        }

        // ── GL items ──────────────────────────────────────────────────────────
        foreach ($glItems as $gl) {
            $amount = (float)$gl['amount'];
            if ($amount == 0) continue;

            // DR Accounts Payable
            GldTransaction::create([
                'trans_no'     => $scn->id,
                'type'         => StockMovement::TYPE_CREDIT_NOTE,
                'tran_date'    => $tranDate,
                'account_code' => $payableAccount,
                'reference'    => $ref,
                'narration'    => ($gl['memo'] ?? '') ?: "SCN GL entry ({$ref})",
                'amount'       => $amount,
                'created_by'   => $user,
                'dimension_id' => $gl['dimension_id'] ?? null,
                'dimension2_id'=> $gl['dimension2_id'] ?? null,
            ]);

            // CR specified account
            GldTransaction::create([
                'trans_no'     => $scn->id,
                'type'         => StockMovement::TYPE_CREDIT_NOTE,
                'tran_date'    => $tranDate,
                'account_code' => $gl['account_code'],
                'reference'    => $ref,
                'narration'    => ($gl['memo'] ?? '') ?: "SCN GL credit ({$ref})",
                'amount'       => -$amount,
                'created_by'   => $user,
                'dimension_id' => $gl['dimension_id'] ?? null,
                'dimension2_id'=> $gl['dimension2_id'] ?? null,
            ]);
        }
    }
}
