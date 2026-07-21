<?php

namespace App\Http\Controllers\Sales;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CreditNote;
use App\Models\CustomerPayment;
use App\Models\DebtorAllocation;
use App\Services\Sales\AllocationService;
use App\Models\GldTransaction;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\StockMovement;
use App\Services\Etims\EtimsService;
use App\Services\Inventory\StockMovementService;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Traits\ValidatesSellingPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesInvoiceController extends Controller
{
    use ValidatesSellingPrice;
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recalcTotals(SalesInvoice $invoice): void
    {
        $subTotal = $invoice->items()->get()->sum(function ($item) {
            return $item->qty * $item->price * (1 - $item->discount_pct / 100);
        });
        $invoice->sub_total    = round($subTotal, 2);
        $invoice->amount_total = round($subTotal + $invoice->shipping_charge, 2);
        $invoice->save();
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
        $ref = DB::transaction(fn() => SalesInvoice::nextInvNo());
        return ApiResponse::success(['ref' => $ref], 'Next reference generated');
    }

    public function index(Request $request): JsonResponse
    {
        $allocatedSub = DebtorAllocation::selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('inv_id', 'sales_invoices.id');

        $customerUnallocatedSub = CustomerPayment::selectRaw('COALESCE(SUM(unallocated_amount), 0)')
            ->whereColumn('debtor_no', 'sales_invoices.debtor_no')
            ->where('status', 'posted');

        $query = SalesInvoice::with(['customer:debtor_no,name'])
            ->selectRaw('sales_invoices.*')
            ->selectSub($allocatedSub, 'allocated_amount')
            ->selectSub($customerUnallocatedSub, 'customer_unallocated')
            ->orderByDesc('id');

        if ($v = $request->get('debtor_no'))   $query->where('debtor_no', $v);
        if ($v = $request->get('location_id')) $query->where('location_id', $v);
        if ($v = $request->get('date_from'))   $query->where('invoice_date', '>=', $v);
        if ($v = $request->get('date_to'))     $query->where('invoice_date', '<=', $v);
        if ($v = $request->get('status'))      $query->where('status', $v);

        // Graders only see their own invoices — same created_by scoping as
        // MilkPurchaseController::index(). Office/web users (no grader role)
        // keep the unscoped, all-invoices view.
        if (auth()->user()?->hasRole('grader')) {
            $query->where('created_by', auth()->user()->user_id);
        }

        $invoices = $query->paginate(min((int) $request->get('per_page', 20), 100));

        // Append outstanding_balance to each invoice
        $invoices->getCollection()->transform(function ($inv) {
            $inv->outstanding_balance = round($inv->amount_total - $inv->allocated_amount, 2);
            return $inv;
        });

        return ApiResponse::paginated($invoices, 'Invoices retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'debtor_no'       => 'required|string|max:10',
            'so_id'           => 'nullable|integer|exists:sales_orders,id',
            'dn_id'           => 'nullable|integer|exists:sales_deliveries,id',
            'invoice_date'    => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'branch_id'       => 'nullable|integer',
            'due_date'        => 'nullable|date',
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
            'payment_provider'     => 'nullable|string|in:mpesa,bank,cash,cheque',
            'payment_channel_code' => 'nullable|string|max:20|exists:gl_accounts,code',
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

        // Create and place in one atomic transaction — an invoice must never
        // persist as 'draft': either everything below succeeds and it's
        // committed already-placed (stock moved, GL posted), or any guard
        // failure inside placeInvoiceTx() throws, the whole transaction
        // (including the invoice/items rows created here) rolls back, and
        // nothing is left behind for the user to retry into a duplicate.
        // (Previously this only created the draft row; the app made a
        // separate POST .../place call afterwards — if that second call
        // failed, e.g. on a missing buying price, the draft was permanently
        // stranded with no delete/retry path, which is exactly how orphaned
        // Draft invoices piled up.)
        $company     = DB::table('company_preferences')->first();
        $glSetting   = DB::table('gl_settings')->first();
        $shortfalls  = [];
        $missingCost = [];

        try {
            $placed = DB::transaction(function () use ($validated, $company, $glSetting, &$shortfalls, &$missingCost) {
                $invoice = SalesInvoice::create([
                    'inv_no'             => 'TEMP-' . uniqid(),
                    'so_id'              => $validated['so_id'] ?? null,
                    'dn_id'              => $validated['dn_id'] ?? null,
                    'debtor_no'          => $validated['debtor_no'],
                    'branch_id'          => $validated['branch_id'] ?? null,
                    'invoice_date'       => $validated['invoice_date'],
                    'due_date'           => $validated['due_date'] ?? null,
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
                    'payment_provider'      => $validated['payment_provider'] ?? null,
                    'payment_channel_code'  => $validated['payment_channel_code'] ?? null,
                    'shipping_company_id'=> $validated['shipping_company_id'] ?? null,
                    'status'             => 'draft',
                    'created_by'         => auth()->user()?->user_id ?? 'system',
                ]);

                $invoice->update(['inv_no' => SalesInvoice::nextInvNo()]);

                $subTotal = 0;
                foreach ($validated['items'] as $itemData) {
                    $lineTotal = $this->calcLineTotal($itemData);
                    $subTotal += $lineTotal;
                    SalesInvoiceItem::create([
                        'inv_id'        => $invoice->id,
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

                $invoice->sub_total    = round($subTotal, 2);
                $invoice->amount_total = round($subTotal + ($validated['shipping_charge'] ?? 0), 2);
                $invoice->save();

                try {
                    broadcast(new DashboardEvent('sales', 'invoice_created'));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
                }

                return $this->placeInvoiceTx($invoice->fresh('items'), $company, $glSetting, $shortfalls, $missingCost);
            });
        } catch (\RuntimeException $e) {
            return $this->guardFailureResponse($e, $shortfalls, $missingCost);
        }

        return $this->finalizePlacedResponse($placed, 'Sales invoice created and placed');
    }
   function checkCreditNoteItems($inv_id){
      return DB::table('credit_note_items as cni')
        ->join('credit_notes as cn', 'cn.id', '=', 'cni.cn_id')
        ->where('cn.inv_id', $inv_id)
        ->select('cni.stock_id', DB::raw('SUM(cni.qty) as qty'))
        ->groupBy('cni.stock_id')
        ->get();
   }
    public function show(int $id): JsonResponse
    {
        $invoice = SalesInvoice::with(['items', 'customer', 'allocations'])->find($id);
        if (! $invoice) {
            return ApiResponse::notFound('Sales invoice not found');
        }
         
        $invoice->allocated_amount    = round($invoice->allocations->sum('amount'), 2);
        $invoice->outstanding_balance = round($invoice->amount_total - $invoice->allocated_amount, 2);
        return ApiResponse::success($invoice, 'Sales invoice retrieved');
    }
public function showCredit(int $id): JsonResponse
{
    $invoice = SalesInvoice::with(['items', 'customer', 'allocations'])->find($id);

    if (! $invoice) {
        return ApiResponse::notFound('Sales invoice not found');
    }

    // get credited qty per stock_id
    $creditNotes = DB::table('credit_note_items as cni')
        ->join('credit_notes as cn', 'cn.id', '=', 'cni.cn_id')
        ->where('cn.inv_id', $invoice->id)
        ->select(
            'cni.stock_id',
            DB::raw('SUM(cni.qty) as credited_qty')
        )
        ->groupBy('cni.stock_id')
        ->get()
        ->keyBy('stock_id');

    // transform invoice items
    $invoice->setRelation('items', $invoice->items->map(function ($item) use ($creditNotes) {

        $invoicedQty = (float) $item->qty;

        $creditedQty = isset($creditNotes[$item->stock_id])
            ? (float) $creditNotes[$item->stock_id]->credited_qty
            : 0;

        // ✅ FINAL RULE
        $remainingQty = $invoicedQty - $creditedQty;

        return [
            'id'             => $item->id,
            'stock_id'       => $item->stock_id,
            'description'    => $item->description,

            // core logic
            'qty'            => max(0, $remainingQty),
            'invoiced_qty'   => $invoicedQty,
            'credited_qty'   => $creditedQty,
            'remaining_qty'  => max(0, $remainingQty),

            // optional
            'price'          => (float) $item->price,
            'line_total'     => (float) $item->line_total,
        ];
    }));

    // allocations
    $invoice->allocated_amount = round($invoice->allocations->sum('amount'), 2);
    $invoice->outstanding_balance = round(
        $invoice->amount_total - $invoice->allocated_amount,
        2
    );

    return ApiResponse::success($invoice, 'Sales invoice retrieved');
}
    public function allocations(int $id): JsonResponse
    {
        $invoice = SalesInvoice::find($id);
        if (! $invoice) return ApiResponse::notFound('Sales invoice not found');

        $allocations = DebtorAllocation::where('debtor_allocations.inv_id', $id)
            ->leftJoin('credit_notes', function ($join) {
                $join->on('credit_notes.id', '=', 'debtor_allocations.source_id')
                     ->where('debtor_allocations.source_type', '=', 'credit_note');
            })
            ->leftJoin('customer_payments', function ($join) {
                $join->on('customer_payments.id', '=', 'debtor_allocations.source_id')
                     ->where('debtor_allocations.source_type', '=', 'payment');
            })
            ->select(
                'debtor_allocations.*',
                'credit_notes.cn_type',
                'customer_payments.payment_type'
            )
            ->orderByDesc('debtor_allocations.allocated_date')
            ->get();

        $totalAllocated = round($allocations->sum('amount'), 2);

        return ApiResponse::success([
            'invoice'             => ['id' => $invoice->id, 'inv_no' => $invoice->inv_no, 'amount_total' => $invoice->amount_total],
            'allocations'         => $allocations,
            'total_allocated'     => $totalAllocated,
            'outstanding_balance' => round($invoice->amount_total - $totalAllocated, 2),
        ], 'Allocations retrieved');
    }

    public function applyPayments(int $id, AllocationService $allocationService): JsonResponse
    {
        $invoice = SalesInvoice::find($id);
        if (! $invoice) return ApiResponse::notFound('Sales invoice not found');
        if ($invoice->status !== 'placed') return ApiResponse::error('Only placed invoices can receive payment allocations', 422);

        $alreadyAlloc = (float) DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
        $outstanding  = round((float) $invoice->amount_total - $alreadyAlloc, 2);
        if ($outstanding <= 0) return ApiResponse::error('Invoice is already fully settled', 422);

        $totalUnallocated = (float) CustomerPayment::where('debtor_no', $invoice->debtor_no)
            ->where('status', 'posted')
            ->where('unallocated_amount', '>', 0)
            ->sum('unallocated_amount');

        if ($totalUnallocated <= 0) return ApiResponse::error('Customer has no unallocated payment balance', 422);

        return DB::transaction(function () use ($invoice, $allocationService) {
            $createdBy = auth()->user()?->user_id ?? 'system';
            $applied   = $allocationService->applyUnallocatedPayments($invoice, now()->toDateString(), $createdBy);

            if ($applied <= 0) return ApiResponse::error('No unallocated payments could be applied', 422);

            return ApiResponse::success(
                ['applied' => $applied],
                'Applied KES ' . number_format($applied, 2) . ' to invoice ' . $invoice->inv_no
            );
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = SalesInvoice::find($id);
        if (! $invoice) {
            return ApiResponse::notFound('Sales invoice not found');
        }

        $validated = $request->validate([
            'invoice_date'    => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'due_date'        => 'nullable|date',
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
            'payment_provider'     => 'nullable|string|in:mpesa,bank,cash,cheque',
            'payment_channel_code' => 'nullable|string|max:20|exists:gl_accounts,code',
            'shipping_company_id' => 'nullable|integer',
            'branch_id'       => 'nullable|integer',
        ]);

        $invoice->update($validated);
        $this->recalcTotals($invoice);

        return ApiResponse::updated($invoice->load('items'), 'Sales invoice updated');
    }

    /**
     * Legacy two-step endpoint — still used to retry/resume a genuinely
     * stranded draft (e.g. one created before this atomic-store change
     * shipped). New invoice creation (store() above) no longer relies on
     * this: it calls placeInvoiceTx() directly inside its own transaction.
     */
    public function place(int $id): JsonResponse
    {
        $invoice = SalesInvoice::with('items')->find($id);
        if (! $invoice) {
            return ApiResponse::notFound('Sales invoice not found');
        }
        if ($invoice->status !== 'draft') {
            return ApiResponse::error('Invoice is not in draft status', 422);
        }

        $company   = DB::table('company_preferences')->first();
        $glSetting = DB::table('gl_settings')->first();

        $shortfalls  = [];
        $missingCost = [];

        try {
            // Deliberately not an arrow fn (fn () => ...): those capture
            // $shortfalls/$missingCost by value, so placeInvoiceTx()'s
            // reference params would populate a copy instead of these
            // variables — guardFailureResponse() below would then always
            // see empty arrays regardless of what actually failed.
            $placed = DB::transaction(function () use ($invoice, $company, $glSetting, &$shortfalls, &$missingCost) {
                return $this->placeInvoiceTx($invoice, $company, $glSetting, $shortfalls, $missingCost);
            });
        } catch (\RuntimeException $e) {
            return $this->guardFailureResponse($e, $shortfalls, $missingCost);
        }

        return $this->finalizePlacedResponse($placed, 'Sales invoice placed');
    }

    /**
     * Runs every guard check + GL/stock posting needed to turn a draft
     * invoice into a placed one, and flips its status — all within whatever
     * transaction the caller is already in. Throws \RuntimeException
     * ('missing_buying_price' | 'insufficient_stock') on a failed guard;
     * the caller's transaction rolls back automatically since the exception
     * propagates out uncaught.
     */
    private function placeInvoiceTx(SalesInvoice $invoice, $company, $glSetting, array &$shortfalls, array &$missingCost): SalesInvoice
    {
            // Resolve stock location code for movements; fall back to first active location
            $locCode = $invoice->location_id
                ? DB::table('inventory_locations')->where('id', $invoice->location_id)->value('code')
                : DB::table('inventory_locations')->where('inactive', 0)->value('code');

            // ── Buying-price check — an item can't post accurate COGS/gross profit
            // without a known cost, so refuse to place the invoice until it's set ──
            foreach ($invoice->items as $invoiceItem) {
                $mbFlag = DB::table('items')->where('stock_id', $invoiceItem->stock_id)->value('mb_flag');
                if ($mbFlag === 'S') continue; // service items carry no buying price

                $cost = (float) ($invoiceItem->standard_cost ?? 0);
                if ($cost <= 0) {
                    $cost = (float) DB::table('items')
                        ->where('stock_id', $invoiceItem->stock_id)
                        ->selectRaw('COALESCE(purchase_cost,0) + COALESCE(material_cost,0) + COALESCE(labour_cost,0) + COALESCE(overhead_cost,0) as total_cost')
                        ->value('total_cost');
                }
                if ($cost <= 0) {
                    $missingCost[] = $invoiceItem->stock_id;
                }
            }
            if (!empty($missingCost)) {
                throw new \RuntimeException('missing_buying_price');
            }

            // ── Stock availability check — a sale must not silently no-op when
            // the chosen location doesn't actually hold the stock being sold ──
            if (!($glSetting->allow_negative_inventory ?? false)) {
                foreach ($invoice->items as $invoiceItem) {
                    $mbFlag = DB::table('items')->where('stock_id', $invoiceItem->stock_id)->value('mb_flag');
                    if ($mbFlag === 'S') continue; // service items aren't stock-controlled

                    $available = StockMovementService::availableQty($invoiceItem->stock_id, $locCode, lockForUpdate: true);
                    if ($available < (float) $invoiceItem->qty) {
                        $shortfalls[] = [
                            'stock_id'  => $invoiceItem->stock_id,
                            'required'  => (float) $invoiceItem->qty,
                            'available' => max(0, $available),
                        ];
                    }
                }
                if (!empty($shortfalls)) {
                    throw new \RuntimeException('insufficient_stock');
                }
            }

            $invoice->update(['status' => 'placed']);

            $createdBy  = auth()->user()?->user_id ?? 'system';
            $invNo      = $invoice->inv_no;
            $tranDate   = $invoice->invoice_date instanceof \Carbon\Carbon
                ? $invoice->invoice_date->toDateString()
                : $invoice->invoice_date;

            $totalGrossSales = 0.0;
            $totalDiscounts  = 0.0;
            $totalTax        = 0.0;

            foreach ($invoice->items as $invoiceItem) {
                $qty          = (float) $invoiceItem->qty;
                $standardCost = (float) ($invoiceItem->standard_cost ?? 0);

                if ($standardCost == 0) {
                    $itemCost = DB::table('items')
                        ->where('stock_id', $invoiceItem->stock_id)
                        ->selectRaw('COALESCE(purchase_cost,0) + COALESCE(material_cost,0) + COALESCE(labour_cost,0) + COALESCE(overhead_cost,0) as total_cost')
                        ->value('total_cost');
                    $standardCost = (float) ($itemCost ?? 0);

                    // Persist the recalculated cost onto the line item — otherwise
                    // it stays 0 forever and any report reading standard_cost
                    // straight off sales_invoice_items shows COGS = 0 for a sale
                    // that (via the GL entry below) actually has a real cost.
                    if ($standardCost > 0) {
                        $invoiceItem->update(['standard_cost' => $standardCost]);
                    }
                }

                $item = DB::table('items')
                    ->leftJoin('item_categories', 'item_categories.id', '=', 'items.category_id')
                    ->where('items.stock_id', $invoiceItem->stock_id)
                    ->select(
                        'items.cogs_account', 'items.inventory_account', 'items.sales_account',
                        'items.tax_type_id', 'items.dimension_id', 'items.dimension2_id', 'items.mb_flag',
                        'item_categories.cogs_gl_account as cat_cogs_account',
                        'item_categories.inventory_gl_account as cat_inventory_account',
                        'item_categories.sales_gl_account as cat_sales_account'
                    )
                    ->first();

                // ── Stock movement: goods out — FIFO batch deduction ──────────
                // Service items (mb_flag = 'S') aren't stock-controlled — skip
                // the physical movement entirely.
                if (! $item || $item->mb_flag !== 'S') {
                    StockMovementService::deductFifo(
                        $invoiceItem->stock_id,
                        $locCode,
                        $qty,
                        [
                            'trans_no'  => $invoice->id,
                            'type'      => StockMovement::TYPE_DELIVERY,
                            'tran_date' => $tranDate,
                            'reference' => $invNo,
                            'user_name' => $createdBy,
                            'comments'  => $invoice->comments ?? '',
                            'vehicle'   => $invoice->vehicle  ?? '',
                            'shift'     => $invoice->shift    ?? '',
                        ],
                        allowNegative: (bool) ($glSetting->allow_negative_inventory ?? false)
                    );
                }

                $dimId  = ($item->dimension_id  ?? null) ?: $invoice->dimension_id;
                $dim2Id = ($item->dimension2_id ?? null) ?: $invoice->dimension2_id;

                // ── COGS / Inventory ──────────────────────────────────────────
                // An item with no cogs_account/inventory_account configured must
                // still post COGS — otherwise a real sale (with a real cost, per
                // the fallback above) silently posts revenue with no matching
                // expense, understating COGS and overstating profit. Fall back
                // item → item's category → company default (gl_settings.items_*)
                // → a safe hardcoded account, the same chain sales_account uses.
                if ($item) {
                    $cogsAccount = ($item->cogs_account ?: null)
                        ?: ($item->cat_cogs_account ?: null)
                        ?: ($glSetting->items_cogs_account ?: null)
                        ?: '501010';
                    $inventoryAccount = ($item->inventory_account ?: null)
                        ?: ($item->cat_inventory_account ?: null)
                        ?: ($glSetting->items_inventory_account ?: null)
                        ?: '103039';

                    $cogsAmount = round($standardCost * $qty, 4);
                    if ($cogsAmount != 0) {
                        GldTransaction::create([
                            'trans_no'     => $invoice->id,
                            'type'         => StockMovement::TYPE_INVOICE,
                            'tran_date'    => $tranDate,
                            'account_code' => $cogsAccount,
                            'reference'    => $invNo,
                            'narration'    => "COGS — {$invoiceItem->description} ({$invNo})",
                            'amount'       => $cogsAmount,
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);
                        GldTransaction::create([
                            'trans_no'     => $invoice->id,
                            'type'         => StockMovement::TYPE_INVOICE,
                            'tran_date'    => $tranDate,
                            'account_code' => $inventoryAccount,
                            'reference'    => $invNo,
                            'narration'    => "Inventory out — {$invoiceItem->description} ({$invNo})",
                            'amount'       => -$cogsAmount,
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);
                    }
                }

                // ── Revenue: Sales / Tax / Discount ───────────────────────────
                if ($item) {
                    $salesAccount   = ($item->sales_account ?: null)
                        ?: ($item->cat_sales_account ?: null)
                        ?: ($glSetting->items_sales_account ?: null)
                        ?: ($glSetting->sales_account ?: null)
                        ?: 'SALES_REVENUE';

                    $grossAmount    = round($qty * $invoiceItem->price, 2);
                    $discountAmount = round($grossAmount * ($invoiceItem->discount_pct / 100), 2);
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
                        'trans_no'     => $invoice->id,
                        'type'         => StockMovement::TYPE_INVOICE,
                        'tran_date'    => $tranDate,
                        'account_code' => $salesAccount,
                        'reference'    => $invNo,
                        'narration'    => "Sales — {$invoiceItem->description} ({$invNo})",
                        'amount'       => -$grossAmount,
                        'created_by'   => $createdBy,
                        'dimension_id' => $dimId,
                        'dimension2_id'=> $dim2Id,
                    ]);

                    // CR Tax Payable
                    if ($taxAccount && $taxAmount != 0) {
                        GldTransaction::create([
                            'trans_no'     => $invoice->id,
                            'type'         => StockMovement::TYPE_INVOICE,
                            'tran_date'    => $tranDate,
                            'account_code' => $taxAccount,
                            'reference'    => $invNo,
                            'narration'    => "Tax — {$invoiceItem->description} ({$invNo})",
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
                            'trans_no'     => $invoice->id,
                            'type'         => StockMovement::TYPE_INVOICE,
                            'tran_date'    => $tranDate,
                            'account_code' => $discountGlCode,
                            'reference'    => $invNo,
                            'narration'    => "Discount — {$invoiceItem->description} ({$invNo})",
                            'amount'       => $discountAmount,
                            'created_by'   => $createdBy,
                            'dimension_id' => $dimId,
                            'dimension2_id'=> $dim2Id,
                        ]);
                    }
                }
            }

            // ── CR Shipping Income ────────────────────────────────────────────
            $shippingCharge = (float) ($invoice->shipping_charge ?? 0);
            if ($shippingCharge != 0) {
                $shippingAccount = ($glSetting->shipping_charged_account ?: null) ?: 'SHIPPING_INCOME';
                GldTransaction::create([
                    'trans_no'     => $invoice->id,
                    'type'         => StockMovement::TYPE_INVOICE,
                    'tran_date'    => $tranDate,
                    'account_code' => $shippingAccount,
                    'reference'    => $invNo,
                    'narration'    => "Shipping — {$invNo}",
                    'amount'       => -$shippingCharge,
                    'created_by'   => $createdBy,
                    'dimension_id' => $invoice->dimension_id,
                    'dimension2_id'=> $invoice->dimension2_id,
                ]);
            }

            // ── DR Debtors = gross - discounts + tax + shipping ───────────────
            // (tax increases what customer owes; discount reduces it)
            $debtorsAmount = round($totalGrossSales - $totalDiscounts + $totalTax + $shippingCharge, 2);
            if ($debtorsAmount != 0) {
                $debtorsGlCode = ($company->debtors_gl_code ?? null) ?: 'DEBTORS';
                GldTransaction::create([
                    'trans_no'     => $invoice->id,
                    'type'         => StockMovement::TYPE_INVOICE,
                    'tran_date'    => $tranDate,
                    'account_code' => $debtorsGlCode,
                    'reference'    => $invNo,
                    'narration'    => "Debtors — {$invNo}",
                    'amount'       => round($debtorsAmount, 2),
                    'created_by'   => $createdBy,
                    'dimension_id' => $invoice->dimension_id,
                    'dimension2_id'=> $invoice->dimension2_id,
                ]);
            }

            // ── Auto-allocate unallocated customer payments + credit notes (FIFO) ──
            $alloc = app(AllocationService::class);
            $freshInvoice = $invoice->fresh();
            $alloc->applyUnallocatedPayments($freshInvoice, $tranDate, $createdBy);
            $alloc->applyUnallocatedCreditNotes($freshInvoice, $tranDate, $createdBy);

            try {
                broadcast(new DashboardEvent('sales', 'placed', ['inv_no' => $invoice->inv_no, 'amount' => $invoice->amount_total]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }
            return $invoice->fresh();
    }

    /** Builds the error response for a guard failure thrown by placeInvoiceTx(). */
    private function guardFailureResponse(\RuntimeException $e, array $shortfalls, array $missingCost): JsonResponse
    {
        if ($e->getMessage() === 'insufficient_stock') {
            $lines = array_map(
                fn($s) => "{$s['stock_id']}: need {$s['required']}, have {$s['available']}",
                $shortfalls
            );
            return ApiResponse::error(
                'Insufficient stock at the selected location — ' . implode('; ', $lines),
                422
            );
        }
        if ($e->getMessage() === 'missing_buying_price') {
            return ApiResponse::error(
                'Cannot place invoice — missing buying price for: ' . implode(', ', $missingCost)
                    . '. Set a purchase cost (or material/labour/overhead cost) for these items before selling them.',
                422
            );
        }
        throw $e;
    }

    /** eTIMS stamping + auto payment-initiation once an invoice is placed — shared tail for store() and place(). */
    private function finalizePlacedResponse(SalesInvoice $placed, string $message): JsonResponse
    {
        if (! in_array($placed->etims_status, ['signed', 'stamped'])) {
            try {
                app(EtimsService::class)->stampInvoice($placed);
            } catch (\Throwable $e) {
                Log::error('eTIMS stampInvoice failed: ' . $e->getMessage());
            }
        }

        // ── Auto-initiate collection for online payment channels ───────────────
        // A channel chosen at invoice-entry time (payment_provider/payment_channel_code —
        // see store()) means the customer intends to pay this way; placing the
        // invoice is the natural trigger. Best-effort like eTIMS above: a failed
        // STK push / bank-instructions lookup must not roll back or block the
        // already-committed invoice — the payment can always be retried via
        // POST /sales/payments/initiate.
        $paymentChannelMap = ['mpesa' => 'mpesa_stk', 'bank' => 'bank_transfer'];
        $payment = null;

        if ($channel = $paymentChannelMap[$placed->payment_provider] ?? null) {
            try {
                $transaction = app(PaymentService::class)->initiate([
                    'channel'           => $channel,
                    'debtor_no'         => $placed->debtor_no,
                    'inv_no'            => $placed->inv_no,
                    'amount'            => $placed->amount_total,
                    'phone'             => $placed->contact_phone ?: $placed->customer?->phone,
                    'bank_account_code' => $placed->payment_channel_code,
                    'memo'              => "Invoice {$placed->inv_no}",
                    'initiated_by'      => auth()->user()?->user_id ?? 'system',
                ]);

                $payment = [
                    'reference'    => $transaction->reference,
                    'channel'      => $transaction->channel,
                    'status'       => $transaction->status,
                    'instructions' => $transaction->raw_response['instructions'] ?? null,
                ];
            } catch (\Throwable $e) {
                Log::error('Payment initiation failed for invoice ' . $placed->inv_no . ': ' . $e->getMessage());
                $payment = ['channel' => $channel, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        $result = $placed->fresh();
        $result->payment = $payment;

        return ApiResponse::success($result, $message);
    }

    public function stamp(int $id): JsonResponse
    {
        $invoice = SalesInvoice::with('items')->find($id);
        if (! $invoice) {
            return ApiResponse::notFound('Sales invoice not found');
        }
        if ($invoice->status !== 'placed') {
            return ApiResponse::error('Only placed invoices can be stamped', 422);
        }
        if (in_array($invoice->etims_status, ['signed', 'stamped'])) {
            return ApiResponse::error('Invoice has already been submitted to KRA eTIMS', 422);
        }

        try {
            app(EtimsService::class)->stampInvoice($invoice);
        } catch (\Throwable $e) {
            Log::error('Manual eTIMS stamp failed: ' . $e->getMessage());
            return ApiResponse::error('eTIMS stamping failed: ' . $e->getMessage(), 502);
        }

        return ApiResponse::success($invoice->fresh(), 'Invoice submitted to KRA eTIMS');
    }

    public function cancel(int $id): JsonResponse
    {
        $invoice = SalesInvoice::find($id);
        if (! $invoice) {
            return ApiResponse::notFound('Sales invoice not found');
        }
        if ($invoice->status === 'placed') {
            return ApiResponse::error('Cannot cancel a placed invoice', 422);
        }

        $invoice->update(['status' => 'cancelled']);
        return ApiResponse::success($invoice, 'Sales invoice cancelled');
    }

    public function addItem(Request $request, int $id): JsonResponse
    {
        $invoice = SalesInvoice::find($id);
        if (! $invoice) {
            return ApiResponse::notFound('Sales invoice not found');
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

        $item = SalesInvoiceItem::create([
            'inv_id'        => $invoice->id,
            'stock_id'      => $validated['stock_id'],
            'description'   => $validated['description'],
            'qty'           => $validated['qty'],
            'unit'          => $validated['unit'] ?? null,
            'price'         => $validated['price'],
            'standard_cost' => $validated['standard_cost'] ?? 0,
            'discount_pct'  => $validated['discount_pct'] ?? 0,
            'line_total'    => $lineTotal,
        ]);

        $this->recalcTotals($invoice);

        return ApiResponse::created($item, 'Item added');
    }

    public function updateItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $item = SalesInvoiceItem::where('inv_id', $id)->where('id', $itemId)->first();
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

        $invoice = SalesInvoice::find($id);
        if ($invoice) $this->recalcTotals($invoice);

        return ApiResponse::updated($item, 'Item updated');
    }

    public function removeItem(int $id, int $itemId): JsonResponse
    {
        $item = SalesInvoiceItem::where('inv_id', $id)->where('id', $itemId)->first();
        if (! $item) {
            return ApiResponse::notFound('Item not found');
        }

        $item->delete();

        $invoice = SalesInvoice::find($id);
        if ($invoice) $this->recalcTotals($invoice);

        return ApiResponse::deleted('Item removed');
    }

    public function glEntries(int $id): JsonResponse
    {
        $invoice = SalesInvoice::with('customer')->find($id);
        if (! $invoice) return ApiResponse::notFound('Invoice not found');

        $company = DB::table('company_preferences')->first();

        $entries = GldTransaction::where('type', StockMovement::TYPE_INVOICE)
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
            'delivery'     => [   // keep key as 'delivery' for GLJournalModal compatibility
                'id'            => $invoice->id,
                'dn_no'         => $invoice->inv_no,
                'delivery_date' => $invoice->invoice_date instanceof \Carbon\Carbon
                    ? $invoice->invoice_date->toDateString()
                    : $invoice->invoice_date,
                'customer_name' => $invoice->customer?->name,
                'debtor_no'     => $invoice->debtor_no,
            ],
            'entries'      => $enriched,
            'total_debit'  => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
        ], 'GL entries retrieved');
    }

    /**
     * Return the returnable items for a placed invoice.
     * Calculates remaining returnable qty = original qty − already returned via credit notes.
     */
    public function returnableItems(int $id): JsonResponse
    {
        $invoice = SalesInvoice::with(['items', 'customer:debtor_no,name'])->find($id);
        if (! $invoice) return ApiResponse::notFound('Invoice not found');

        $items = $invoice->items->map(function ($item) use ($id) {
            $alreadyReturned = \App\Models\CreditNoteItem::whereHas('creditNote', function ($q) use ($id) {
                    $q->where('inv_id', $id)->whereIn('cn_type', ['return', 'return_to_store', 'damage'])->whereIn('status', ['placed']);
                })
                ->where('stock_id', $item->stock_id)
                ->sum('qty');

            $originalQty    = (float) $item->qty + (float) $alreadyReturned;
            $returnableQty  = max(0, (float) $item->qty - (float) $alreadyReturned);

            $standardCost = (float) (DB::table('items')
                ->where('stock_id', $item->stock_id)
                ->selectRaw('COALESCE(material_cost,0)+COALESCE(labour_cost,0)+COALESCE(overhead_cost,0) as c')
                ->value('c') ?? 0);

            return [
                'stock_id'      => $item->stock_id,
                'description'   => $item->description,
                'qty'           => (float) $item->qty,
                'returnable_qty'=> $returnableQty,
                'already_returned' => (float) $alreadyReturned,
                'unit'          => $item->unit,
                'price'         => (float) $item->price,
                'discount_pct'  => (float) ($item->discount_pct ?? 0),
                'standard_cost' => $standardCost,
            ];
        })->filter(fn($i) => $i['returnable_qty'] > 0)->values();

        return ApiResponse::success([
            'invoice' => [
                'id'            => $invoice->id,
                'inv_no'        => $invoice->inv_no,
                'invoice_date'  => $invoice->invoice_date,
                'debtor_no'     => $invoice->debtor_no,
                'customer_name' => $invoice->customer?->name,
                'location_id'   => $invoice->location_id,
            ],
            'items' => $items,
        ], 'Returnable items retrieved');
    }
}
