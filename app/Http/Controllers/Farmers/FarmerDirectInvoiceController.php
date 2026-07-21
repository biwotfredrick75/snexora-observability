<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FarmerDirectInvoice;
use App\Models\FarmerDirectInvoiceItem;
use App\Models\GldTransaction;
use App\Models\StockMovement;
use App\Events\DashboardEvent;
use App\Services\Esp\PartyCreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FarmerDirectInvoiceController extends Controller
{
    // ── Form-data for dropdowns ───────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        $locations = DB::table('inventory_locations')
            ->where('inactive', false)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $vehicles = DB::table('stock_movements')
            ->whereNotNull('vehicle')
            ->where('vehicle', '!=', '')
            ->distinct()
            ->orderBy('vehicle')
            ->limit(100)
            ->pluck('vehicle')
            ->map(fn ($v) => ['id' => $v, 'name' => $v]);

        $shifts = DB::table('milk_collection_shifts')
            ->where('active', true)
            ->orderBy('description')
            ->get(['id', 'description']);

        $shippingCompanies = DB::table('shipping_companies')
            ->where('inactive', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        $paymentTerms = DB::table('payment_terms')
            ->where('inactive', false)
            ->orderBy('description')
            ->get(['id', 'description']);

        $dimensions = DB::table('dimensions')
            ->orderBy('name')
            ->get(['id', 'reference', 'name']);

        return ApiResponse::success([
            'locations'         => $locations,
            'vehicles'          => $vehicles,
            'shifts'            => $shifts,
            'shipping_companies'=> $shippingCompanies,
            'payment_terms'     => $paymentTerms,
            'dimensions'        => $dimensions,
        ], 'Form data retrieved');
    }

    // ── Reserve reference (gap-free sequential) ───────────────────────────────
    public function reserveReference(Request $request): JsonResponse
    {
        $draft = DB::transaction(function () use ($request) {
            $seq   = (FarmerDirectInvoice::lockForUpdate()->max('seq') ?? 0) + 1;
            $invNo = FarmerDirectInvoice::nextInvNo();

            return FarmerDirectInvoice::create([
                'seq'          => $seq,
                'inv_no'       => $invNo,
                'invoice_date' => now()->toDateString(),
                'status'       => 'draft',
                'created_by'   => $request->user()?->user_id ?? 'system',
            ]);
        });

        return ApiResponse::success([
            'reference_no' => $draft->inv_no,
            'draft_id'     => $draft->id,
        ], 'Reference reserved');
    }

    // ── Discard an unused draft ───────────────────────────────────────────────
    public function discardDraft(string $id): JsonResponse
    {
        FarmerDirectInvoice::where('id', $id)->where('status', 'draft')->delete();
        return ApiResponse::success(null, 'Draft discarded');
    }

    // ── Farmer info for badges ────────────────────────────────────────────────
    public function farmerInfo(string $farmerId): JsonResponse
    {
        $farmer = DB::table('farmers')->where('id', $farmerId)->first();
        if (! $farmer) {
            return ApiResponse::notFound('Farmer not found');
        }

        // Credit score follows the same formula as the ESP module (see PartyCreditService):
        // this month's milk value minus already-invoiced ESP sales and checkoffs/advances.
        // Direct invoices already placed draw against that same milk-value pool, so they're
        // subtracted here too — otherwise a farmer could double-spend across both channels.
        $score = app(PartyCreditService::class)->farmerScore((int) $farmerId);

        $directInvoicesPlaced = (float) FarmerDirectInvoice::where('farmer_id', $farmerId)
            ->where('status', 'placed')
            ->sum('amount_total');

        $netBalance    = $score['gross_value'] - $score['already_invoiced'] - $score['current_debts'] - $directInvoicesPlaced;
        $currentCredit = round(max(0.0, $netBalance), 2);
        $currentDebt   = round(max(0.0, -$netBalance), 2);

        // Milk this month: quantity
        $totalMilk = (float) DB::table('milk_purchase_items')
            ->join('milk_purchases', 'milk_purchases.id', '=', 'milk_purchase_items.purchase_id')
            ->where('milk_purchases.route_id', $farmer->route_id)
            ->whereMonth('milk_purchases.invoice_date', now()->month)
            ->whereYear('milk_purchases.invoice_date', now()->year)
            ->where('milk_purchase_items.farmer_id', $farmerId)
            ->sum('milk_purchase_items.quantity');

        // Invoice credit limit
        $prefs         = DB::table('company_preferences')->first();
        $allowInvoicing = (bool) ($prefs->allow_farmer_invoicing ?? false);
        $limitPercent   = (float) ($prefs->farmer_invoice_limit_percent ?? 0);
        $invoiceLimit   = ($allowInvoicing && $limitPercent > 0)
            ? round($score['gross_value'] * $limitPercent / 100, 2)
            : null;

        $paymentTerms = null;
        if ($farmer->payment_terms_id) {
            $paymentTerms = DB::table('payment_terms')
                ->where('id', $farmer->payment_terms_id)
                ->value('id');
        }

        return ApiResponse::success([
            'current_credit'         => $currentCredit,
            'current_debt'           => $currentDebt,
            'total_milk'             => $totalMilk,
            'milk_value'             => round($score['gross_value'], 2),
            'allow_farmer_invoicing' => $allowInvoicing,
            'invoice_limit'          => $invoiceLimit,
            'payment_terms'          => $paymentTerms,
            'price_list_label'       => '',
            'deliver_to'             => $farmer->full_name ?? '',
            'address'                => $farmer->address   ?? '',
            'phone'                  => $farmer->phone     ?? '',
        ], 'Farmer info retrieved');
    }

    // ── Update draft before placing ───────────────────────────────────────────
    public function update(Request $request, string $id): JsonResponse
    {
        $invoice = FarmerDirectInvoice::findOrFail($id);

        if ($invoice->status === 'placed') {
            return ApiResponse::error('Cannot edit a placed invoice', 422);
        }

        $data = $request->validate([
            'farmer_id'          => 'required|integer|exists:farmers,id',
            'invoice_date'       => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'due_date'           => 'nullable|date',
            'payment_terms'      => 'nullable|integer',
            'no_loading_order'   => 'nullable|boolean',
            'shipping_charge'    => 'nullable|numeric|min:0',
            'vehicle'            => 'nullable|string|max:60',
            'shift_id'           => 'nullable|integer',
            'deliver_to'         => 'nullable|string|max:150',
            'address'            => 'nullable|string',
            'contact_phone'      => 'nullable|string|max:30',
            'customer_ref'       => 'nullable|string|max:60',
            'comments'           => 'nullable|string',
            'shipping_company_id'=> 'nullable|integer',
            'items'              => 'required|array|min:1',
            'items.*.stock_id'   => 'required|string|max:20',
            'items.*.description'=> 'required|string|max:200',
            'items.*.qty'        => 'required|numeric|min:0.0001',
            'items.*.qty_required'=> 'nullable|numeric|min:0',
            'items.*.unit'       => 'nullable|string|max:20',
            'items.*.location_id'=> 'nullable|integer',
            'items.*.price'      => 'required|numeric|min:0',
            'items.*.discount_pct' => 'nullable|numeric|min:0|max:100',
            'items.*.standard_cost'=> 'nullable|numeric|min:0',
        ]);

        $invoice->update([
            'farmer_id'          => $data['farmer_id'],
            'invoice_date'       => $data['invoice_date'],
            'due_date'           => $data['due_date']         ?? null,
            'payment_terms'      => $data['payment_terms']    ?? null,
            'no_loading_order'   => $data['no_loading_order'] ?? false,
            'shipping_charge'    => $data['shipping_charge']  ?? 0,
            'vehicle'            => $data['vehicle']          ?? null,
            'shift_id'           => $data['shift_id']         ?? null,
            'deliver_to'         => $data['deliver_to']       ?? null,
            'address'            => $data['address']          ?? null,
            'contact_phone'      => $data['contact_phone']    ?? null,
            'customer_ref'       => $data['customer_ref']     ?? null,
            'comments'           => $data['comments']         ?? null,
            'shipping_company_id'=> $data['shipping_company_id'] ?? null,
        ]);

        // Replace items
        $invoice->items()->delete();
        $subTotal = 0;
        foreach ($data['items'] as $line) {
            $lineTotal = $this->calcLineTotal($line);
            $subTotal += $lineTotal;
            FarmerDirectInvoiceItem::create([
                'invoice_id'   => $invoice->id,
                'stock_id'     => $line['stock_id'],
                'description'  => $line['description'],
                'qty'          => $line['qty'],
                'qty_required' => $line['qty_required'] ?? $line['qty'],
                'unit'         => $line['unit'] ?? null,
                'location_id'  => $line['location_id'] ?? null,
                'price'        => $line['price'],
                'discount_pct' => $line['discount_pct'] ?? 0,
                'standard_cost'=> $line['standard_cost'] ?? 0,
                'line_total'   => $lineTotal,
            ]);
        }

        $invoice->update([
            'sub_total'    => round($subTotal, 4),
            'amount_total' => round($subTotal + ($data['shipping_charge'] ?? 0), 4),
        ]);

        return ApiResponse::updated($invoice->load('items'), 'Invoice updated');
    }

    // ── Place invoice (post GL + stock) ───────────────────────────────────────
    public function place(string $id): JsonResponse
    {
        $invoice = FarmerDirectInvoice::with('items')->findOrFail($id);

        if ($invoice->status !== 'draft') {
            return ApiResponse::error('Invoice is not in draft status', 422);
        }
        if ($invoice->items->isEmpty()) {
            return ApiResponse::error('Invoice has no items', 422);
        }

        $glSetting = DB::table('gl_settings')->first();
        $company   = DB::table('company_preferences')->first();

        // ── Farmer invoice credit limit check ─────────────────────────────────
        if ($company && $company->allow_farmer_invoicing && $company->farmer_invoice_limit_percent > 0) {
            $farmer = DB::table('farmers')->where('id', $invoice->farmer_id)->first();
            if ($farmer) {
                $milkValue = (float) DB::table('milk_purchase_items')
                    ->join('milk_purchases', 'milk_purchases.id', '=', 'milk_purchase_items.purchase_id')
                    ->where('milk_purchases.route_id', $farmer->route_id)
                    ->whereMonth('milk_purchases.invoice_date', now()->month)
                    ->whereYear('milk_purchases.invoice_date', now()->year)
                    ->where('milk_purchase_items.farmer_id', $invoice->farmer_id)
                    ->sum('milk_purchase_items.total_price');

                $limit = round($milkValue * (float)$company->farmer_invoice_limit_percent / 100, 2);

                $alreadyInvoiced = (float) FarmerDirectInvoice::where('farmer_id', $invoice->farmer_id)
                    ->where('status', 'placed')
                    ->whereMonth('invoice_date', now()->month)
                    ->whereYear('invoice_date', now()->year)
                    ->where('id', '!=', $invoice->id)
                    ->sum('amount_total');

                if ($limit > 0 && ($alreadyInvoiced + $invoice->amount_total) > $limit) {
                    return ApiResponse::error(
                        sprintf(
                            'Invoice exceeds the allowed credit limit of KES %.2f. Already invoiced: KES %.2f, This invoice: KES %.2f.',
                            $limit, $alreadyInvoiced, $invoice->amount_total
                        ),
                        422
                    );
                }
            }
        }

        return DB::transaction(function () use ($invoice, $glSetting, $company) {
            $invoice->update(['status' => 'placed']);

            $createdBy  = auth()->user()?->user_id ?? 'system';
            $invNo      = $invoice->inv_no;
            $tranDate   = $invoice->invoice_date instanceof \Carbon\Carbon
                ? $invoice->invoice_date->toDateString()
                : $invoice->invoice_date;

            $locCode = null;
            $totalGross = 0.0;

            foreach ($invoice->items as $invItem) {
                $qty          = (float) $invItem->qty;
                $standardCost = (float) ($invItem->standard_cost ?: 0);

                // Resolve location code — fall back to first active location
                $locCode = $invItem->location_id
                    ? DB::table('inventory_locations')->where('id', $invItem->location_id)->value('code')
                    : DB::table('inventory_locations')->where('inactive', false)->orderBy('id')->value('code');

                if ($standardCost == 0) {
                    $itemCost = DB::table('items')
                        ->where('stock_id', $invItem->stock_id)
                        ->selectRaw('COALESCE(purchase_cost,0)+COALESCE(material_cost,0)+COALESCE(labour_cost,0)+COALESCE(overhead_cost,0) as total_cost')
                        ->value('total_cost');
                    $standardCost = (float) ($itemCost ?? 0);
                }

                // Stock out
                StockMovement::create([
                    'trans_no'      => $invoice->id,
                    'stock_id'      => $invItem->stock_id,
                    'type'          => StockMovement::TYPE_DELIVERY,
                    'loc_code'      => $locCode,
                    'tran_date'     => $tranDate,
                    'date_moved'    => $tranDate,
                    'qty'           => -$qty,
                    'price'         => $invItem->price,
                    'standard_cost' => $standardCost,
                    'reference'     => $invNo,
                    'comments'      => $invoice->comments ?? '',
                    'user_name'     => $createdBy,
                    'vehicle'       => $invoice->vehicle ?? '',
                    'shift'         => '',
                    'approved'      => 1,
                ]);

                $item = DB::table('items')->where('stock_id', $invItem->stock_id)
                    ->select('cogs_account', 'inventory_account', 'sales_account', 'tax_type_id')
                    ->first();

                // COGS / Inventory GL
                if ($item && $item->cogs_account && $item->inventory_account) {
                    $cogsAmt = round($standardCost * $qty, 4);
                    if ($cogsAmt != 0) {
                        GldTransaction::create([
                            'trans_no'     => $invoice->id,
                            'type'         => StockMovement::TYPE_INVOICE,
                            'tran_date'    => $tranDate,
                            'account_code' => $item->cogs_account,
                            'reference'    => $invNo,
                            'narration'    => "COGS — {$invItem->description} ({$invNo})",
                            'amount'       => $cogsAmt,
                            'created_by'   => $createdBy,
                        ]);
                        GldTransaction::create([
                            'trans_no'     => $invoice->id,
                            'type'         => StockMovement::TYPE_INVOICE,
                            'tran_date'    => $tranDate,
                            'account_code' => $item->inventory_account,
                            'reference'    => $invNo,
                            'narration'    => "Inventory out — {$invItem->description} ({$invNo})",
                            'amount'       => -$cogsAmt,
                            'created_by'   => $createdBy,
                        ]);
                    }
                }

                // Revenue GL
                if ($item && $item->sales_account) {
                    $grossAmt    = round($qty * $invItem->price, 4);
                    $discountAmt = round($grossAmt * ($invItem->discount_pct / 100), 4);
                    $netAfterDisc= $grossAmt - $discountAmt;

                    $taxAmt     = 0.0;
                    $taxAccount = null;
                    if ($item->tax_type_id) {
                        $taxType = DB::table('tax_types')->where('id', $item->tax_type_id)->first();
                        if ($taxType && (float) $taxType->default_rate > 0) {
                            $taxAmt     = round($netAfterDisc * (float) $taxType->default_rate / 100, 4);
                            $taxAccount = $taxType->sales_gl_account ?: null;
                        }
                    }

                    $netRevenue = $netAfterDisc - $taxAmt;
                    $totalGross += $grossAmt;

                    GldTransaction::create([
                        'trans_no'     => $invoice->id,
                        'type'         => StockMovement::TYPE_INVOICE,
                        'tran_date'    => $tranDate,
                        'account_code' => $item->sales_account,
                        'reference'    => $invNo,
                        'narration'    => "Sales — {$invItem->description} ({$invNo})",
                        'amount'       => -$netRevenue,
                        'created_by'   => $createdBy,
                    ]);

                    if ($taxAccount && $taxAmt != 0) {
                        GldTransaction::create([
                            'trans_no'     => $invoice->id,
                            'type'         => StockMovement::TYPE_INVOICE,
                            'tran_date'    => $tranDate,
                            'account_code' => $taxAccount,
                            'reference'    => $invNo,
                            'narration'    => "Tax — {$invItem->description} ({$invNo})",
                            'amount'       => -$taxAmt,
                            'created_by'   => $createdBy,
                        ]);
                    }

                    if ($discountAmt != 0) {
                        $discGl = ($company->discount_gl_code ?? null)
                            ?: ($glSetting->sales_discount_account ?? null)
                            ?: 'DISCOUNT';
                        GldTransaction::create([
                            'trans_no'     => $invoice->id,
                            'type'         => StockMovement::TYPE_INVOICE,
                            'tran_date'    => $tranDate,
                            'account_code' => $discGl,
                            'reference'    => $invNo,
                            'narration'    => "Discount — {$invItem->description} ({$invNo})",
                            'amount'       => -$discountAmt,
                            'created_by'   => $createdBy,
                        ]);
                    }
                }
            }

            // DR Debtors (farmers owe us)
            if ($totalGross != 0) {
                // debtors_account was never a real gl_settings column —
                // receivable_account is the actual AR control account.
                $debtorsGl = ($company->debtors_gl_code ?? null) ?: ($glSetting->receivable_account ?? null) ?: '104018';
                GldTransaction::create([
                    'trans_no'     => $invoice->id,
                    'type'         => StockMovement::TYPE_INVOICE,
                    'tran_date'    => $tranDate,
                    'account_code' => $debtorsGl,
                    'reference'    => $invNo,
                    'narration'    => "Farmer Invoice — {$invNo}",
                    'amount'       => round($totalGross, 2),
                    'created_by'   => $createdBy,
                ]);
            }

            try {
                broadcast(new DashboardEvent('direct_sale', 'placed', ['inv_no' => $invNo, 'amount' => $totalGross]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }

            return ApiResponse::success($invoice->fresh(), "Invoice {$invNo} placed successfully");
        });
    }

    // ── Cancel invoice ────────────────────────────────────────────────────────
    public function cancel(string $id): JsonResponse
    {
        $invoice = FarmerDirectInvoice::findOrFail($id);

        if ($invoice->status === 'placed') {
            return ApiResponse::error('Cannot cancel a placed invoice. Raise a credit note instead.', 422);
        }

        $invoice->update(['status' => 'cancelled']);
        return ApiResponse::success($invoice, 'Invoice cancelled');
    }

    // ── List invoices ─────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = FarmerDirectInvoice::with(['farmer:id,farmer_no,full_name'])
            ->whereIn('status', ['placed', 'draft', 'cancelled'])
            ->orderByDesc('id');

        if ($request->filled('farmer_id')) $query->where('farmer_id', $request->farmer_id);
        if ($request->filled('status'))    $query->where('status', $request->status);
        if ($request->filled('from'))      $query->whereDate('invoice_date', '>=', $request->from);
        if ($request->filled('to'))        $query->whereDate('invoice_date', '<=', $request->to);

        return ApiResponse::success(
            $query->paginate(min((int) $request->get('per_page', 20), 100)),
            'Invoices retrieved'
        );
    }

    // ── Show single invoice ───────────────────────────────────────────────────
    public function show(string $id): JsonResponse
    {
        $invoice = FarmerDirectInvoice::with(['items', 'farmer'])->findOrFail($id);
        return ApiResponse::success($invoice, 'Invoice retrieved');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function calcLineTotal(array $item): float
    {
        return round(
            floatval($item['qty']) * floatval($item['price']) * (1 - floatval($item['discount_pct'] ?? 0) / 100),
            4
        );
    }
}
