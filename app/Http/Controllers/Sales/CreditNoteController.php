<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\DebtorAllocation;
use App\Models\GldTransaction;
use App\Models\SalesInvoiceItem;
use App\Models\StockMovement;
use App\Services\Sales\AllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditNoteController extends Controller
{
    private function calcLineTotal(array $item): float
    {
        return round(
            floatval($item['qty']) * floatval($item['price']) * (1 - floatval($item['discount_pct'] ?? 0) / 100),
            2
        );
    }

    public function index(Request $request): JsonResponse
    {
        $query = CreditNote::with(['customer:debtor_no,name', 'reason:id,description', 'invoice:id,inv_no'])->orderByDesc('id');

        if ($v = $request->get('debtor_no'))  $query->where('debtor_no', $v);
        if ($v = $request->get('date_from'))  $query->where('cn_date', '>=', $v);
        if ($v = $request->get('date_to'))    $query->where('cn_date', '<=', $v);
        if ($v = $request->get('status'))     $query->where('status', $v);
        if ($v = $request->get('cn_type'))    $query->where('cn_type', $v);

        $notes = $query->paginate(min((int) $request->get('per_page', 20), 100));

        return ApiResponse::paginated($notes, 'Credit notes retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inv_id'               => 'nullable|integer|exists:sales_invoices,id',
            'cn_type'              => 'required|in:return,discount,write_off',
            'debtor_no'            => 'required|string|max:10|exists:customers,debtor_no',
            'branch_id'            => 'nullable|string|max:10',
            'customer_credit_ref'  => 'nullable|string|max:60',
            'sales_type_id'        => 'nullable|integer',
            'shipping_company_id'  => 'nullable|integer',
            'shipping_charge'      => 'nullable|numeric|min:0',
            'dimension_id'         => 'nullable|integer',
            'dimension2_id'        => 'nullable|integer',
            'cn_date'              => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'reason_id'            => 'nullable|integer|exists:credit_note_reasons,id',
            'location_id'          => 'nullable|integer',
            'comments'             => 'nullable|string',
            'write_off_amount'     => 'nullable|numeric|min:0',
            'items'                => 'nullable|array',
            'items.*.stock_id'     => 'required_with:items|string|max:20',
            'items.*.description'  => 'required_with:items|string|max:200',
            'items.*.qty'          => 'required_with:items|numeric|min:0.0001',
            'items.*.price'        => 'required_with:items|numeric|min:0',
            'items.*.discount_pct' => 'nullable|numeric|min:0|max:100',
            'items.*.unit'         => 'nullable|string|max:20',
            'items.*.standard_cost'=> 'nullable|numeric|min:0',
        ]);

        // All types need items; write_off may also provide write_off_amount directly
        if (empty($validated['items'])) {
            if ($validated['cn_type'] === 'write_off' && ! empty($validated['write_off_amount'])) {
                // flat amount write-off with no line items — allowed
            } else {
                return ApiResponse::validationError(['items' => ['At least one item is required']]);
            }
        }

        return DB::transaction(function () use ($validated) {
            $cn = CreditNote::create([
                'cn_no'               => 'TEMP-' . uniqid(),
                'inv_id'              => $validated['inv_id'] ?? null,
                'cn_type'             => $validated['cn_type'],
                'debtor_no'           => $validated['debtor_no'],
                'branch_id'           => $validated['branch_id'] ?? null,
                'customer_credit_ref' => $validated['customer_credit_ref'] ?? null,
                'sales_type_id'       => $validated['sales_type_id'] ?? null,
                'shipping_company_id' => $validated['shipping_company_id'] ?? null,
                'shipping_charge'     => $validated['shipping_charge'] ?? 0,
                'dimension_id'        => $validated['dimension_id'] ?? null,
                'dimension2_id'       => $validated['dimension2_id'] ?? null,
                'cn_date'             => $validated['cn_date'],
                'reason_id'           => $validated['reason_id'] ?? null,
                'location_id'         => $validated['location_id'] ?? null,
                'write_off_amount'    => $validated['write_off_amount'] ?? 0,
                'comments'            => $validated['comments'] ?? null,
                'status'              => 'draft',
                'created_by'         => auth()->user()?->user_id ?? 'system',
            ]);

            $cn->update(['cn_no' => CreditNote::nextCnNo()]);

            $subTotal = 0;

            if (! empty($validated['items'])) {
                foreach ($validated['items'] as $itemData) {
                    $lineTotal = $this->calcLineTotal($itemData);
                    $subTotal += $lineTotal;
                    CreditNoteItem::create([
                        'cn_id'         => $cn->id,
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
                // For write_off, derive write_off_amount from item totals
                if ($validated['cn_type'] === 'write_off') {
                    $cn->write_off_amount = round($subTotal, 2);
                    $cn->save();
                }
            } else {
                // flat write_off_amount with no items
                $subTotal = (float) ($validated['write_off_amount'] ?? 0);
            }

            $shipping = (float) ($validated['shipping_charge'] ?? 0);
            $total    = round($subTotal + $shipping, 2);
            $cn->sub_total          = round($subTotal, 2);
            $cn->amount_total       = $total;
            $cn->unallocated_amount = $total;
            $cn->save();

            return ApiResponse::created($cn->load('items'), 'Credit note created');
        });
    }

    public function show(int $id): JsonResponse
    {
        $cn = CreditNote::with(['items', 'customer', 'reason', 'invoice'])->find($id);
        if (! $cn) return ApiResponse::notFound('Credit note not found');
        return ApiResponse::success($cn, 'Credit note retrieved');
    }

    public function place(int $id): JsonResponse
    {
        $cn = CreditNote::with('items')->find($id);
        if (! $cn) return ApiResponse::notFound('Credit note not found');
        if ($cn->status !== 'draft') return ApiResponse::error('Credit note is not in draft status', 422);

        $company   = DB::table('company_preferences')->first();
        $glSetting = DB::table('gl_settings')->first();

        return DB::transaction(function () use ($cn, $company, $glSetting) {
            $cn->update(['status' => 'placed']);

            $createdBy = auth()->user()?->user_id ?? 'system';
            $cnNo      = $cn->cn_no;
            $tranDate  = $cn->cn_date instanceof \Carbon\Carbon
                ? $cn->cn_date->toDateString()
                : $cn->cn_date;

            $debtorsGlCode = ($company->debtors_gl_code ?? null) ?: 'DEBTORS';

            match ($cn->cn_type) {
                'return'    => $this->placeReturn($cn, $company, $glSetting, $createdBy, $cnNo, $tranDate, $debtorsGlCode),
                'discount'  => $this->placeDiscount($cn, $glSetting, $createdBy, $cnNo, $tranDate, $debtorsGlCode),
                'write_off' => $this->placeWriteOff($cn, $glSetting, $createdBy, $cnNo, $tranDate, $debtorsGlCode),
            };

            // Reduce original invoice item quantities (match by stock_id)
            if ($cn->inv_id) {
                foreach ($cn->items as $cnItem) {
                    SalesInvoiceItem::where('inv_id', $cn->inv_id)
                        ->where('stock_id', $cnItem->stock_id)
                        ->decrement('qty', (float) $cnItem->qty);
                }
            }

            // Auto-allocate against the linked invoice (inside this transaction)
            app(AllocationService::class)->allocateCreditNote($cn->fresh());

            return ApiResponse::success($cn->fresh(), 'Credit note placed');
        });
    }

    // ── Return: goods back to inventory + COGS reversal + sales reversal ──────

    private function placeReturn(CreditNote $cn, object $company, object $glSetting, string $createdBy, string $cnNo, string $tranDate, string $debtorsGlCode): void
    {
        // Resolve location code: credit note location → invoice location → first active location
        $locationId = $cn->location_id
            ?? ($cn->inv_id ? DB::table('sales_invoices')->where('id', $cn->inv_id)->value('location_id') : null);

        $locCode = $locationId
            ? DB::table('inventory_locations')->where('id', $locationId)->value('code')
            : DB::table('inventory_locations')->where('inactive', 0)->value('code');

        $totalGrossReturns = 0.0;

        foreach ($cn->items as $cnItem) {
            $qty          = (float) $cnItem->qty;
            $standardCost = $this->resolveStandardCost($cnItem);

            // Stock movement: goods back in (positive qty)
            StockMovement::create([
                'trans_no'      => $cn->id,
                'stock_id'      => $cnItem->stock_id,
                'type'          => StockMovement::TYPE_CREDIT_NOTE,
                'loc_code'      => $locCode,
                'tran_date'     => $tranDate,
                'date_moved'    => $tranDate,
                'qty'           => $qty,
                'price'         => $cnItem->price,
                'standard_cost' => $standardCost,
                'reference'     => $cnNo,
                'comments'      => $cn->comments,
                'user_name'     => $createdBy,
                'approved'      => 1,
            ]);

            $item = $this->itemGlAccounts($cnItem->stock_id);

            // Reverse COGS: CR COGS, DR Inventory (goods returned at cost)
            if ($item && $item->cogs_account && $item->inventory_account) {
                $cogsAmount = round($standardCost * $qty, 4);
                if ($cogsAmount != 0) {
                    $this->glEntry($cn->id, $tranDate, $item->cogs_account,   -$cogsAmount, "CN Return COGS — {$cnItem->description} ({$cnNo})", $cnNo, $createdBy);
                    $this->glEntry($cn->id, $tranDate, $item->inventory_account, $cogsAmount, "CN Return Inventory — {$cnItem->description} ({$cnNo})", $cnNo, $createdBy);
                }
            }

            // Reverse Revenue: DR Sales, DR Tax
            if ($item && $item->sales_account) {
                [$grossAmount, $discountAmount, $netAfterDisc, $taxAmount, $taxAccount] = $this->calcRevenueBreakdown($cnItem, $item);

                $netRevenue = $netAfterDisc - $taxAmount;
                $totalGrossReturns += $grossAmount;

                $this->glEntry($cn->id, $tranDate, $item->sales_account, $netRevenue, "CN Return Sales — {$cnItem->description} ({$cnNo})", $cnNo, $createdBy);

                if ($taxAccount && $taxAmount != 0) {
                    $this->glEntry($cn->id, $tranDate, $taxAccount, $taxAmount, "CN Return Tax — {$cnItem->description} ({$cnNo})", $cnNo, $createdBy);
                }
            }
        }

        // CR Debtors
        if ($totalGrossReturns != 0) {
            $this->glEntry($cn->id, $tranDate, $debtorsGlCode, -round($totalGrossReturns, 2), "CN Return Debtors — {$cnNo}", $cnNo, $createdBy);
        }
    }

    // ── Discount: price adjustment, no stock movement ─────────────────────────

    private function placeDiscount(CreditNote $cn, object $glSetting, string $createdBy, string $cnNo, string $tranDate, string $debtorsGlCode): void
    {
        $totalGross = 0.0;

        foreach ($cn->items as $cnItem) {
            $item = $this->itemGlAccounts($cnItem->stock_id);
            $salesAccount = ($item->sales_account ?? null)
                ?: ($glSetting->sales_account ?? null)
                ?: 'SALES';

            [$grossAmount, $discountAmount, $netAfterDisc, $taxAmount, $taxAccount] = $this->calcRevenueBreakdown($cnItem, $item);

            $netRevenue     = $netAfterDisc - $taxAmount;
            $totalGross    += $grossAmount;

            // DR Sales (reduce revenue)
            $this->glEntry($cn->id, $tranDate, $salesAccount, $netRevenue, "CN Discount — {$cnItem->description} ({$cnNo})", $cnNo, $createdBy);

            if ($taxAccount && $taxAmount != 0) {
                $this->glEntry($cn->id, $tranDate, $taxAccount, $taxAmount, "CN Discount Tax — {$cnItem->description} ({$cnNo})", $cnNo, $createdBy);
            }
        }

        // CR Debtors
        if ($totalGross != 0) {
            $this->glEntry($cn->id, $tranDate, $debtorsGlCode, -round($totalGross, 2), "CN Discount Debtors — {$cnNo}", $cnNo, $createdBy);
        }
    }

    // ── Write-off: bad debt, no stock movement ────────────────────────────────

    private function placeWriteOff(CreditNote $cn, object $glSetting, string $createdBy, string $cnNo, string $tranDate, string $debtorsGlCode): void
    {
        $amount = (float) $cn->write_off_amount;
        if ($amount == 0) return;

        // Bad debt / write-off expense account
        $badDebtAccount = ($glSetting->sales_discount_account ?? null) ?: 'BAD_DEBT';

        // DR Bad Debt expense
        $this->glEntry($cn->id, $tranDate, $badDebtAccount, $amount, "Write-off — {$cnNo}", $cnNo, $createdBy);
        // CR Debtors (remove receivable)
        $this->glEntry($cn->id, $tranDate, $debtorsGlCode, -$amount, "Write-off Debtors — {$cnNo}", $cnNo, $createdBy);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveStandardCost(object $cnItem): float
    {
        $cost = (float) ($cnItem->standard_cost ?? 0);
        if ($cost == 0) {
            $cost = (float) (DB::table('items')
                ->where('stock_id', $cnItem->stock_id)
                ->selectRaw('COALESCE(purchase_cost,0)+COALESCE(material_cost,0)+COALESCE(labour_cost,0)+COALESCE(overhead_cost,0) as c')
                ->value('c') ?? 0);
        }
        return $cost;
    }

    private function itemGlAccounts(string $stockId): ?object
    {
        return DB::table('items')->where('stock_id', $stockId)
            ->select('cogs_account', 'inventory_account', 'sales_account', 'tax_type_id')
            ->first();
    }

    private function calcRevenueBreakdown(object $cnItem, ?object $item): array
    {
        $qty            = (float) $cnItem->qty;
        $grossAmount    = round($qty * $cnItem->price, 4);
        $discountAmount = round($grossAmount * ((float) ($cnItem->discount_pct ?? 0) / 100), 4);
        $netAfterDisc   = $grossAmount - $discountAmount;
        $taxAmount      = 0.0;
        $taxAccount     = null;

        if ($item && $item->tax_type_id) {
            $taxType = DB::table('tax_types')->where('id', $item->tax_type_id)->first();
            if ($taxType && (float) $taxType->default_rate > 0) {
                $taxAmount  = round($netAfterDisc * (float) $taxType->default_rate / 100, 4);
                $taxAccount = $taxType->sales_gl_account ?: null;
            }
        }

        return [$grossAmount, $discountAmount, $netAfterDisc, $taxAmount, $taxAccount];
    }

    private function glEntry(int $transNo, string $tranDate, string $accountCode, float $amount, string $narration, string $reference, string $createdBy): void
    {
        GldTransaction::create([
            'trans_no'     => $transNo,
            'type'         => StockMovement::TYPE_CREDIT_NOTE,
            'tran_date'    => $tranDate,
            'account_code' => $accountCode,
            'reference'    => $reference,
            'narration'    => $narration,
            'amount'       => $amount,
            'created_by'   => $createdBy,
        ]);
    }

    // ── Unallocated credit notes for a customer ───────────────────────────────

    public function unallocated(Request $request): JsonResponse
    {
        $debtorNo = $request->get('debtor_no');
        if (! $debtorNo) return ApiResponse::validationError(['debtor_no' => ['Required']]);

        $notes = CreditNote::where('debtor_no', $debtorNo)
            ->where('status', 'placed')
            ->where('unallocated_amount', '>', 0)
            ->orderBy('cn_date')
            ->orderBy('id')
            ->get();

        return ApiResponse::success($notes, 'Unallocated credit notes retrieved');
    }

    // ── Manual allocation of a credit note to specific invoices ──────────────

    public function allocateManual(int $id, Request $request): JsonResponse
    {
        $cn = CreditNote::find($id);
        if (! $cn) return ApiResponse::notFound('Credit note not found');
        if ($cn->status !== 'placed') return ApiResponse::error('Only placed credit notes can be allocated', 422);

        $unallocated = round((float) $cn->unallocated_amount, 2);
        if ($unallocated <= 0) return ApiResponse::error('Credit note has no unallocated balance', 422);

        $request->validate([
            'allocations'          => 'required|array|min:1',
            'allocations.*.inv_id' => 'required|integer|exists:sales_invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $totalRequested = round(array_sum(array_column($request->allocations, 'amount')), 2);
        if ($totalRequested > $unallocated) {
            return ApiResponse::validationError(['allocations' => [
                "Total ({$totalRequested}) exceeds unallocated balance ({$unallocated})"
            ]]);
        }

        $errors = [];
        foreach ($request->allocations as $alloc) {
            $invoice = \App\Models\SalesInvoice::find($alloc['inv_id']);
            if (! $invoice) { $errors[] = "Invoice #{$alloc['inv_id']} not found."; continue; }
            $alreadyAlloc = (float) DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
            $leftToAlloc  = max(0, round($invoice->amount_total - $alreadyAlloc, 2));
            if ($leftToAlloc <= 0) {
                $errors[] = "Invoice {$invoice->inv_no} is already fully paid.";
            } elseif (round((float) $alloc['amount'], 2) > $leftToAlloc) {
                $errors[] = "Invoice {$invoice->inv_no}: requested " . number_format($alloc['amount'], 2) .
                            " exceeds remaining balance " . number_format($leftToAlloc, 2) . ".";
            }
        }
        if (! empty($errors)) return ApiResponse::validationError(['allocations' => $errors]);

        return DB::transaction(function () use ($cn, $request, $unallocated) {
            $cn->refresh()->lockForUpdate();
            $createdBy   = auth()->user()?->user_id ?? 'system';
            $cnDate      = $cn->cn_date instanceof \Carbon\Carbon ? $cn->cn_date->toDateString() : $cn->cn_date;
            $totalApplied = 0.0;

            foreach ($request->allocations as $alloc) {
                $invoice = \App\Models\SalesInvoice::lockForUpdate()->find($alloc['inv_id']);
                $use     = round((float) $alloc['amount'], 2);

                DebtorAllocation::create([
                    'debtor_no'      => $cn->debtor_no,
                    'source_type'    => 'credit_note',
                    'source_id'      => $cn->id,
                    'source_no'      => $cn->cn_no,
                    'inv_id'         => $invoice->id,
                    'inv_no'         => $invoice->inv_no,
                    'amount'         => $use,
                    'allocated_date' => $cnDate,
                    'created_by'     => $createdBy,
                ]);

                $totalApplied += $use;
            }

            $cn->unallocated_amount = max(0, round($unallocated - $totalApplied, 2));
            $cn->allocated_amount   = round((float) $cn->allocated_amount + $totalApplied, 2);
            $cn->save();

            return ApiResponse::success($cn->fresh(), 'Credit note allocated');
        });
    }

    public function cancel(int $id): JsonResponse
    {
        $cn = CreditNote::find($id);
        if (! $cn) return ApiResponse::notFound('Credit note not found');
        if ($cn->status === 'placed') return ApiResponse::error('Cannot cancel a placed credit note', 422);

        $cn->update(['status' => 'cancelled']);
        return ApiResponse::success($cn, 'Credit note cancelled');
    }
}
