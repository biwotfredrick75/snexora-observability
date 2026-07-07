<?php

namespace App\Http\Controllers\Purchases;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteGlItem;
use App\Models\SupplierDebitNoteItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Debit Note — the mirror of Supplier Credit Notes: increases
 * what we owe a supplier (an undercharge correction on a delivery, or a
 * supplementary charge like freight/handling/penalty) instead of
 * decreasing it. Same create-and-post-immediately convention as
 * SupplierCreditNoteController, with the DR/CR legs flipped and no stock
 * movement (a debit note corrects value/charges, not physical quantity).
 */
class SupplierDebitNoteController extends Controller
{
    /**
     * GET /purchases/supplier-debit-notes/form-data
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
     * GET /purchases/supplier-debit-notes
     */
    public function index(Request $request): JsonResponse
    {
        $q = SupplierDebitNote::with('supplier:supplierId,supplierName');

        if ($request->supplier_id) $q->where('supplier_id', $request->supplier_id);
        if ($request->status)      $q->where('status', $request->status);
        if ($request->from)        $q->where('date', '>=', $request->from);
        if ($request->to)          $q->where('date', '<=', $request->to);
        if ($request->sdn_no)      $q->where('sdn_no', 'like', "%{$request->sdn_no}%");

        return ApiResponse::success($q->orderByDesc('id')->get(), 'Debit notes retrieved');
    }

    /**
     * GET /purchases/supplier-debit-notes/{id}/gl
     */
    public function glEntries(int $id): JsonResponse
    {
        $sdn = SupplierDebitNote::with('supplier:supplierId,supplierName')->findOrFail($id);

        $glEntries = DB::table('gld_transactions as g')
            ->leftJoin('gl_accounts as cm', 'cm.code', '=', 'g.account_code')
            ->where('g.trans_no', $sdn->id)
            ->where('g.reference', $sdn->sdn_no)
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
            'sdn'        => $sdn,
            'gl_entries' => $glEntries,
            'company'    => $company ? [
                'name'    => $company->name,
                'phone'   => $company->phone,
                'address' => $company->address,
            ] : [],
        ], 'GL entries');
    }

    /**
     * GET /purchases/supplier-debit-notes/{id}
     */
    public function show(int $id): JsonResponse
    {
        $sdn = SupplierDebitNote::with(['supplier:supplierId,supplierName', 'items', 'glItems'])
            ->findOrFail($id);
        return ApiResponse::success($sdn, 'Debit note retrieved');
    }

    /**
     * POST /purchases/supplier-debit-notes
     * Creates and immediately posts the supplier debit note.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'supplier_id'  => 'required|integer|exists:suppliers,supplierId',
            'date'         => 'required|date',
            'items'        => 'nullable|array',
            'items.*.po_id'          => 'nullable|integer',
            'items.*.stock_id'       => 'nullable|string|max:20',
            'items.*.description'    => 'required_with:items|string|max:200',
            'items.*.qty_to_debit'   => 'required_with:items|numeric|min:0.0001',
            'items.*.price_before_tax'=> 'required_with:items|numeric|min:0',
            'gl_items'     => 'nullable|array',
            'gl_items.*.account_code' => 'required_with:gl_items|string|max:20',
            'gl_items.*.amount'       => 'required_with:gl_items|numeric|min:0.0001',
        ]);

        $items   = $request->items    ?? [];
        $glItems = $request->gl_items ?? [];

        if (empty($items) && empty($glItems)) {
            return ApiResponse::error('Add at least one item or GL entry', 422);
        }

        return DB::transaction(function () use ($request, $items, $glItems) {
            $user = auth()->user()?->user_id ?? 'system';

            $itemsTotal = collect($items)->sum(fn($i) => round((float)$i['qty_to_debit'] * (float)$i['price_before_tax'], 4));
            $glTotal    = collect($glItems)->sum(fn($g) => (float)$g['amount']);
            $total      = $itemsTotal + $glTotal;

            $sdn = SupplierDebitNote::create([
                'sdn_no'        => 'TEMP-' . uniqid(),
                'supplier_id'   => $request->supplier_id,
                'date'          => $request->date,
                'due_date'      => $request->due_date,
                'reference'     => $request->reference ?? '',
                'supplier_ref'  => $request->supplier_ref ?? '',
                'terms'         => $request->terms ?? '',
                'tax_group'     => $request->tax_group ?? '',
                'dimension_id'  => $request->dimension_id,
                'dimension2_id' => $request->dimension2_id,
                'items_total'   => $itemsTotal,
                'gl_total'      => $glTotal,
                'total'         => $total,
                'memo'          => $request->memo ?? '',
                'status'        => 'posted',
                'raised_by'     => $user,
            ]);

            $sdn->update(['sdn_no' => SupplierDebitNote::nextSdnNo()]);

            foreach ($items as $item) {
                $lineTotal = round((float)$item['qty_to_debit'] * (float)$item['price_before_tax'], 4);
                SupplierDebitNoteItem::create([
                    'sdn_id'           => $sdn->id,
                    'po_id'            => $item['po_id'] ?? null,
                    'grn_no'           => $item['grn_no'] ?? '',
                    'stock_id'         => $item['stock_id'] ?? null,
                    'description'      => $item['description'],
                    'qty_to_debit'     => $item['qty_to_debit'],
                    'price_before_tax' => $item['price_before_tax'],
                    'line_total'       => $lineTotal,
                ]);
            }

            foreach ($glItems as $gl) {
                SupplierDebitNoteGlItem::create([
                    'sdn_id'        => $sdn->id,
                    'account_code'  => $gl['account_code'],
                    'account_name'  => $gl['account_name'] ?? '',
                    'dimension_id'  => $gl['dimension_id'] ?? null,
                    'dimension2_id' => $gl['dimension2_id'] ?? null,
                    'amount'        => $gl['amount'],
                    'memo'          => $gl['memo'] ?? '',
                ]);
            }

            $this->postGlEntries($sdn, $items, $glItems, $user);

            try {
                broadcast(new DashboardEvent('purchases', 'supplier_debit_note_posted', [
                    'sdn_no' => $sdn->sdn_no,
                    'amount' => (float) $sdn->total,
                ]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }

            $sdn->load(['supplier:supplierId,supplierName', 'items', 'glItems']);
            return ApiResponse::created($sdn, 'Supplier debit note posted successfully');
        });
    }

    /**
     * POST GL journal entries for the debit note.
     *
     * Delivery items:  CR Accounts Payable (increase liability — we owe more)
     *                  DR Inventory account (increase asset value — price/qty
     *                  correction on goods already received)
     *
     * GL items:        CR Accounts Payable
     *                  DR specified account (e.g. freight/handling expense)
     *
     * No stock movement is posted — a debit note corrects value/charges,
     * not physical quantity on hand.
     */
    private function postGlEntries(SupplierDebitNote $sdn, array $items, array $glItems, string $user): void
    {
        $glSetting      = GlSetting::first();
        $payableAccount = $glSetting?->payable_account ?: 'PAYABLES';
        $tranDate       = $sdn->date->toDateString();
        $ref            = $sdn->sdn_no;

        // ── Delivery items ────────────────────────────────────────────────────
        foreach ($items as $item) {
            $amount = round((float)$item['qty_to_debit'] * (float)$item['price_before_tax'], 4);
            if ($amount == 0) continue;

            $inventoryAccount = 'INVENTORY';
            if (!empty($item['stock_id'])) {
                $itemRecord       = DB::table('items')->where('stock_id', $item['stock_id'])->first();
                $inventoryAccount = $itemRecord?->inventory_account ?: ($glSetting?->items_inventory_account ?: 'INVENTORY');
            }

            // DR Inventory (or expense) account
            GldTransaction::create([
                'trans_no'     => $sdn->id,
                'type'         => StockMovement::TYPE_SUPPLIER_DEBIT_NOTE,
                'tran_date'    => $tranDate,
                'account_code' => $inventoryAccount,
                'reference'    => $ref,
                'narration'    => "SDN charge — {$item['description']} ({$ref})",
                'amount'       => $amount,   // positive = debit
                'created_by'   => $user,
                'dimension_id' => $sdn->dimension_id,
                'dimension2_id'=> $sdn->dimension2_id,
            ]);

            // CR Accounts Payable (increases what we owe)
            GldTransaction::create([
                'trans_no'     => $sdn->id,
                'type'         => StockMovement::TYPE_SUPPLIER_DEBIT_NOTE,
                'tran_date'    => $tranDate,
                'account_code' => $payableAccount,
                'reference'    => $ref,
                'narration'    => "SDN payable — {$item['description']} ({$ref})",
                'amount'       => -$amount,  // negative = credit
                'created_by'   => $user,
                'dimension_id' => $sdn->dimension_id,
                'dimension2_id'=> $sdn->dimension2_id,
            ]);
        }

        // ── GL items ──────────────────────────────────────────────────────────
        foreach ($glItems as $gl) {
            $amount = (float)$gl['amount'];
            if ($amount == 0) continue;

            // DR specified account
            GldTransaction::create([
                'trans_no'     => $sdn->id,
                'type'         => StockMovement::TYPE_SUPPLIER_DEBIT_NOTE,
                'tran_date'    => $tranDate,
                'account_code' => $gl['account_code'],
                'reference'    => $ref,
                'narration'    => ($gl['memo'] ?? '') ?: "SDN GL entry ({$ref})",
                'amount'       => $amount,
                'created_by'   => $user,
                'dimension_id' => $gl['dimension_id'] ?? null,
                'dimension2_id'=> $gl['dimension2_id'] ?? null,
            ]);

            // CR Accounts Payable
            GldTransaction::create([
                'trans_no'     => $sdn->id,
                'type'         => StockMovement::TYPE_SUPPLIER_DEBIT_NOTE,
                'tran_date'    => $tranDate,
                'account_code' => $payableAccount,
                'reference'    => $ref,
                'narration'    => ($gl['memo'] ?? '') ?: "SDN GL payable ({$ref})",
                'amount'       => -$amount,
                'created_by'   => $user,
                'dimension_id' => $gl['dimension_id'] ?? null,
                'dimension2_id'=> $gl['dimension2_id'] ?? null,
            ]);
        }
    }
}
