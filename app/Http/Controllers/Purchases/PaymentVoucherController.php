<?php

namespace App\Http\Controllers\Purchases;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GlAccount;
use App\Models\GldTransaction;
use App\Models\GlSetting;
use App\Models\PaymentVoucher;
use App\Models\PaymentVoucherAllocation;
use App\Models\Supplier;
use App\Models\WithholdingTax;
use App\Services\Blockchain\BlockchainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentVoucherController extends Controller
{
    /**
     * GET /purchases/payment-vouchers/form-data
     * Returns suppliers, bank GL accounts, withholding taxes, payment types
     */
    public function formData(): JsonResponse
    {
        // Bank/cash accounts from GL
        $bankAccounts = GlAccount::where('inactive', false)
            //->whereIn('account_type', ['bank', 'cash', 'Bank', 'Cash', 'BANK', 'CASH'])
            ->select('code as account_code', 'name as account_name' )
            ->orderBy('account_code')
            ->get();

        // Fallback: if no typed accounts, return all active accounts
        if ($bankAccounts->isEmpty()) {
            $bankAccounts = GlAccount::where('inactive', false)
                ->select('code as account_code', 'name as account_name' )
                ->orderBy('account_code')
                ->get();
        }

        $withholdingTaxes = WithholdingTax::where('inactive', false)
            ->select('id', 'description as name', 'tax_rate as rate')
            ->get();

        $types = [
            ['value' => 'bank',  'label' => 'Bank Transfer'],
            ['value' => 'cash',  'label' => 'Cash'],
            ['value' => 'cheque','label' => 'Cheque'],
            ['value' => 'mpesa', 'label' => 'M-Pesa'],
        ];

        return ApiResponse::success(compact('bankAccounts', 'withholdingTaxes', 'types'), 'Form data loaded');
    }

    /**
     * GET /purchases/payment-vouchers/open-transactions?supplier_id=
     * Returns open transactions for a supplier (invoices, GRNs, credit notes NOT fully allocated)
     */
    public function openTransactions(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => 'required|integer']);
        $supplierId = $request->supplier_id;

        $rows = collect();

        // Credit notes (money supplier owes us — negative allocation)
        $creditNotes = DB::table('supplier_credit_notes as scn')
            ->where('scn.supplier_id', $supplierId)
            ->where('scn.status', 'posted')
            ->leftJoin('payment_voucher_allocations as pva', function ($j) {
                $j->on('pva.transaction_id', '=', 'scn.id')
                  ->where('pva.transaction_type', 'Credit Note');
            })
            ->select(
                DB::raw("'Credit Note' as transaction_type"),
                'scn.id',
                'scn.scn_no as supplier_ref',
                'scn.date',
                DB::raw('NULL as due_date'),
                'scn.total as amount',
                DB::raw('COALESCE(SUM(pva.this_allocation),0) as other_allocations'),
                DB::raw('scn.total - COALESCE(SUM(pva.this_allocation),0) as left_to_allocate')
            )
            ->groupBy('scn.id', 'scn.scn_no', 'scn.date', 'scn.total')
            ->havingRaw('scn.total - COALESCE(SUM(pva.this_allocation),0) > 0')
            ->get();
        $rows = $rows->concat($creditNotes);

        // Supplier invoices only — a GRN isn't a payable on its own, it's just
        // the goods receipt; the supplier's actual bill is the invoice raised
        // against it (via "Create Invoice from this GRN"), so only that should
        // ever be payable/allocatable here.
        $invoices = DB::table('purchase_orders as po')
            ->where('po.supplier_id', $supplierId)
            ->where('po.type', 'invoice')
            ->whereIn('po.status', ['received', 'ceo_approved'])
            ->leftJoin('payment_voucher_allocations as pva', function ($j) {
                $j->on('pva.transaction_id', '=', 'po.id')
                  ->where('pva.transaction_type', 'Supplier Invoice');
            })
            ->select(
                DB::raw("'Supplier Invoice' as transaction_type"),
                'po.id',
                'po.po_no as supplier_ref',
                DB::raw('po.order_date as date'),
                DB::raw('NULL as due_date'),
                'po.amount_total as amount',
                DB::raw('COALESCE(SUM(pva.this_allocation),0) as other_allocations'),
                DB::raw('po.amount_total - COALESCE(SUM(pva.this_allocation),0) as left_to_allocate')
            )
            ->groupBy('po.id', 'po.type', 'po.po_no', 'po.order_date', 'po.amount_total')
            ->havingRaw('po.amount_total - COALESCE(SUM(pva.this_allocation),0) > 0')
            ->get();
        $rows = $rows->concat($invoices);

        $rows = $rows->sortBy('date')->values();

        return ApiResponse::success($rows, 'Open transactions loaded');
    }

    /**
     * GET /purchases/payment-vouchers/approved?supplier_id=
     * Returns approved (unposted) vouchers for a supplier — used by Post Voucher Payment
     */
    public function approvedVouchers(Request $request): JsonResponse
    {
        $request->validate(['supplier_id' => 'required|integer']);

        // Any unposted voucher is available for allocation here — post() itself
        // already accepts draft/payables_approved/finance_approved/ceo_approved,
        // so restricting the dropdown to ceo_approved only hid vouchers that
        // were otherwise perfectly postable.
        $vouchers = PaymentVoucher::with('supplier')
            ->where('supplier_id', $request->supplier_id)
            ->whereIn('status', ['draft', 'payables_approved', 'finance_approved', 'ceo_approved'])
            ->orderBy('date_paid')
            ->get()
            ->map(fn($v) => [
                'transaction_type'       => 'Payment Voucher',
                'id'                     => $v->id,
                'supplier_ref'           => $v->pvn_no,
                'date'                   => $v->date_paid?->format('Y-m-d'),
                'due_date'               => null,
                'amount'                 => $v->amount,
                'withholding_tax_amount' => $v->withholding_tax_amount,
                // gross = what must be allocated against open invoices/GRNs/credit notes when posting
                'gross_amount'           => round((float) $v->amount + (float) $v->withholding_tax_amount, 2),
                'other_allocations'      => 0,
                'left_to_allocate'       => $v->amount,
            ]);

        return ApiResponse::success($vouchers, 'Approved vouchers loaded');
    }

    /**
     * GET /purchases/payment-vouchers
     * List vouchers — optional ?status= filter for approval screens
     */
    public function index(Request $request): JsonResponse
    {
        $query = PaymentVoucher::with('supplier')
            ->orderByDesc('date_paid');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('pvn_no')) {
            $query->where('pvn_no', 'like', '%' . $request->pvn_no . '%');
        }
        if ($request->filled('from')) {
            $query->whereDate('date_paid', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('date_paid', '<=', $request->to);
        }

        return ApiResponse::success($query->get(), 'Payment vouchers loaded');
    }

    /**
     * POST /purchases/payment-vouchers
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'supplier_id'            => 'required|integer',
            'bank_account_code'      => 'nullable|string',
            'date_paid'              => 'required|date',
            'reference'              => 'nullable|string|max:60',
            'bank_cheque'            => 'nullable|string|max:60',
            'type'                   => 'nullable|string|max:20',
            'withholding_tax_id'     => 'nullable|integer',
            'withholding_tax_amount' => 'nullable|numeric|min:0',
            'amount'                 => 'required|numeric|min:0',
            'cheque_no'              => 'nullable|string|max:60',
            'memo'                   => 'nullable|string',
            'allocations'            => 'nullable|array',
            'allocations.*.transaction_type' => 'required|string',
            'allocations.*.transaction_id'   => 'required|integer',
            'allocations.*.this_allocation'  => 'required|numeric|min:0',
        ]);

        $allocations = $request->allocations ?? [];
        $gross = round((float) $request->amount + (float) ($request->withholding_tax_amount ?? 0), 2);
        $allocatedTotal = round(collect($allocations)->sum('this_allocation'), 2);
        if ($allocatedTotal > $gross) {
            return ApiResponse::validationError([
                'allocations' => "Allocated total ({$allocatedTotal}) cannot exceed the voucher's gross amount ({$gross})",
            ]);
        }
        foreach ($allocations as $alloc) {
            if (($alloc['this_allocation'] ?? 0) <= 0) continue;
            $maxAllocatable = $this->maxAllocatable($alloc['transaction_type'], $alloc['transaction_id']);
            if (round((float) $alloc['this_allocation'], 2) > round($maxAllocatable, 2) + 0.01) {
                return ApiResponse::validationError([
                    'allocations' => "Allocation of {$alloc['this_allocation']} for {$alloc['transaction_type']} #{$alloc['transaction_id']} exceeds its outstanding balance ({$maxAllocatable})",
                ]);
            }
        }

        DB::beginTransaction();
        try {
            $voucher = PaymentVoucher::create([
                'pvn_no'                 => PaymentVoucher::nextPvnNo(),
                'supplier_id'            => $request->supplier_id,
                'bank_account_code'      => $request->bank_account_code,
                'date_paid'              => $request->date_paid,
                'reference'              => $request->reference,
                'bank_cheque'            => $request->bank_cheque,
                'type'                   => $request->type ?? 'bank',
                'withholding_tax_id'     => $request->withholding_tax_id,
                'withholding_tax_amount' => $request->withholding_tax_amount ?? 0,
                'amount'                 => $request->amount,
                'cheque_no'              => $request->cheque_no,
                'memo'                   => $request->memo,
                'status'                 => 'draft',
                'created_by'             => Auth::id(),
            ]);

            foreach ($request->allocations ?? [] as $alloc) {
                if (($alloc['this_allocation'] ?? 0) > 0) {
                    PaymentVoucherAllocation::create([
                        'payment_voucher_id' => $voucher->id,
                        'transaction_type'   => $alloc['transaction_type'],
                        'transaction_id'     => $alloc['transaction_id'],
                        'this_allocation'    => $alloc['this_allocation'],
                    ]);
                }
            }

            DB::commit();
            return ApiResponse::created($voucher->load('allocations'), 'Payment voucher created');
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::validationError(['error' => $e->getMessage()]);
        }
    }

    /**
     * POST /purchases/payment-vouchers/direct-pay
     * "Direct Payments to Suppliers" — creates and immediately posts a voucher
     * in one step (store() + post() back to back) instead of leaving it
     * sitting in draft waiting for a separate Post Voucher Payment visit.
     */
    public function directPay(Request $request): JsonResponse
    {
        $storeResponse = $this->store($request);
        $storeData = json_decode($storeResponse->getContent(), true);

        if (!($storeData['success'] ?? false)) {
            return $storeResponse;
        }

        return $this->post($request, $storeData['data']['id']);
    }

    /**
     * GET /purchases/payment-vouchers/{id}
     */
    public function show(int $id): JsonResponse
    {
        $voucher = PaymentVoucher::with(['supplier', 'allocations'])->findOrFail($id);

        // Resolve bank account name
        $bankAccount = null;
        if ($voucher->bank_account_code) {
            $bankAccount = DB::table('gl_accounts')
                ->where('code', $voucher->bank_account_code)
                ->select('code as account_code', 'name as account_name')
                ->first();
        }

        return ApiResponse::success(array_merge($voucher->toArray(), [
            'bank_account' => $bankAccount,
        ]), 'Payment voucher loaded');
    }

    /**
     * GET /purchases/payment-vouchers/{id}/gl-transactions
     * Returns GL entries + company info for the GL detail modal
     */
    public function glTransactions(int $id): JsonResponse
    {
        $voucher = PaymentVoucher::with(['supplier', 'allocations'])->findOrFail($id);

        $glEntries = DB::table('gld_transactions as g')
            ->leftJoin('gl_accounts as a', 'a.code', '=', 'g.account_code')
            ->where('g.trans_no', $id)
            ->where('g.type', 22)
            ->select(
                'g.account_code',
                'a.name as account_name',
                'g.narration as memo',
                DB::raw('CASE WHEN g.amount >= 0 THEN g.amount ELSE 0 END as debit'),
                DB::raw('CASE WHEN g.amount < 0 THEN ABS(g.amount) ELSE 0 END as credit'),
                'g.dimension_id',
                'g.dimension2_id',
                'g.tran_date',
                'g.reference'
            )
            ->get();

        $company = DB::table('company_preferences')->first();

        return ApiResponse::success([
            'voucher'    => $voucher,
            'gl_entries' => $glEntries,
            'company'    => $company ? [
                'name'    => $company->name,
                'address' => $company->address,
                'phone'   => $company->phone,
                'email'   => $company->email,
            ] : [],
        ], 'GL transactions loaded');
    }

    /**
     * POST /purchases/payment-vouchers/{id}/payables-approve
     */
    public function payablesApprove(int $id): JsonResponse
    {
        $voucher = PaymentVoucher::findOrFail($id);
        if ($voucher->status !== 'draft') {
            return ApiResponse::validationError(['status' => 'Voucher is not in draft status']);
        }
        $voucher->update(['status' => 'payables_approved']);
        return ApiResponse::success($voucher->fresh(), 'Voucher approved at payables level');
    }

    /**
     * POST /purchases/payment-vouchers/{id}/finance-approve
     */
    public function financeApprove(int $id): JsonResponse
    {
        $voucher = PaymentVoucher::findOrFail($id);
        if ($voucher->status !== 'payables_approved') {
            return ApiResponse::validationError(['status' => 'Voucher must be payables approved first']);
        }
        $voucher->update(['status' => 'finance_approved']);
        return ApiResponse::success($voucher->fresh(), 'Voucher approved at finance level');
    }

    /**
     * POST /purchases/payment-vouchers/{id}/ceo-approve
     */
    public function ceoApprove(int $id): JsonResponse
    {
        $voucher = PaymentVoucher::findOrFail($id);
        if ($voucher->status !== 'finance_approved') {
            return ApiResponse::validationError(['status' => 'Voucher must be finance approved first']);
        }
        $voucher->update(['status' => 'ceo_approved']);
        return ApiResponse::success($voucher->fresh(), 'Voucher approved at CEO level');
    }

    /**
     * POST /purchases/payment-vouchers/{id}/post
     * Posts the voucher to GL: DR Payables / CR Bank
     *
     * Allocations are made here (not at entry time) — the approved voucher's
     * amount is allocated against the supplier's open invoices/GRNs/credit
     * notes as part of posting. If the voucher already has allocations
     * (e.g. legacy vouchers entered the old way), those are used as-is.
     */
    public function post(Request $request, int $id): JsonResponse
    {
        $voucher = PaymentVoucher::with('allocations')->findOrFail($id);

        if (!in_array($voucher->status, ['ceo_approved', 'finance_approved', 'payables_approved', 'draft'])) {
            return ApiResponse::validationError(['status' => 'Voucher already posted']);
        }

        $request->validate([
            'allocations'                     => 'nullable|array',
            'allocations.*.transaction_type'  => 'required_with:allocations|string',
            'allocations.*.transaction_id'    => 'required_with:allocations|integer',
            'allocations.*.this_allocation'   => 'required_with:allocations|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $gross = round((float) $voucher->amount + (float) $voucher->withholding_tax_amount, 2);

            // Allocations may already exist (set at store() time, e.g. by the
            // one-step Direct Payment flow) or arrive now — either way they go
            // through the same validation before anything posts, so the GL
            // always balances regardless of which step attached them.
            if ($voucher->allocations->isEmpty() && $request->filled('allocations')) {
                foreach ($request->allocations as $alloc) {
                    PaymentVoucherAllocation::create([
                        'payment_voucher_id' => $voucher->id,
                        'transaction_type'   => $alloc['transaction_type'],
                        'transaction_id'     => $alloc['transaction_id'],
                        'this_allocation'    => $alloc['this_allocation'],
                    ]);
                }
                $voucher->load('allocations');
            }

            $allocatedTotal = round($voucher->allocations->sum('this_allocation'), 2);
            if ($allocatedTotal > $gross) {
                DB::rollBack();
                return ApiResponse::validationError([
                    'allocations' => "Allocated total ({$allocatedTotal}) cannot exceed the voucher's gross amount ({$gross})",
                ]);
            }

            // Each row can't absorb more than its own outstanding balance — the
            // total matching the voucher gross isn't enough on its own, since
            // that lets one row silently soak up another's shortfall.
            foreach ($voucher->allocations as $alloc) {
                $maxAllocatable = $this->maxAllocatable($alloc->transaction_type, $alloc->transaction_id, $alloc->id);
                if (round((float) $alloc->this_allocation, 2) > round($maxAllocatable, 2) + 0.01) {
                    DB::rollBack();
                    return ApiResponse::validationError([
                        'allocations' => "Allocation of {$alloc->this_allocation} for {$alloc->transaction_type} #{$alloc->transaction_id} exceeds its outstanding balance ({$maxAllocatable})",
                    ]);
                }
            }

            // Any unallocated remainder is paid ahead of an invoice — parked as
            // a supplier advance rather than blocking the post.
            $advanceAmount = round($gross - $allocatedTotal, 2);

            // GL type code 22 = Supplier Payment (see GlInquiryController::typeLabels).
            // trans_no must be the integer voucher id — gld_transactions.trans_no is
            // unsignedInteger, it can't hold a "PV-123" style string.
            $glType    = 22;
            $apAccount = GlSetting::first()?->payable_account ?: '201010';

            // CR Bank/Cash account (payment going out)
            GldTransaction::create([
                'trans_no'   => $voucher->id,
                'type'       => $glType,
                'tran_date'  => $voucher->date_paid,
                'account_code'=> $voucher->bank_account_code ?? '',
                'reference'  => $voucher->pvn_no,
                'narration'  => 'Supplier payment - ' . $voucher->memo,
                'amount'     => -$voucher->amount, // credit
                'created_by' => Auth::id(),
            ]);

            // DR Accounts Payable (for each allocation)
            foreach ($voucher->allocations as $alloc) {
                GldTransaction::create([
                    'trans_no'    => $voucher->id,
                    'type'        => $glType,
                    'tran_date'   => $voucher->date_paid,
                    'account_code'=> $apAccount,
                    'reference'   => $voucher->pvn_no,
                    'narration'   => 'Supplier payment allocation - ' . $alloc->transaction_type . ' #' . $alloc->transaction_id,
                    'amount'      => $alloc->this_allocation, // debit
                    'created_by'  => Auth::id(),
                ]);
            }

            // DR Supplier Advance — unallocated remainder, paid ahead of any
            // recorded invoice. Keeps the batch balanced without forcing 100%
            // allocation against existing open transactions.
            if ($advanceAmount > 0) {
                $advanceAccount = GlSetting::first()?->supplier_advance_account ?: '104021';
                GldTransaction::create([
                    'trans_no'    => $voucher->id,
                    'type'        => $glType,
                    'tran_date'   => $voucher->date_paid,
                    'account_code'=> $advanceAccount,
                    'reference'   => $voucher->pvn_no,
                    'narration'   => 'Supplier advance - ' . $voucher->pvn_no . ' (' . ($voucher->supplier->supplierName ?? 'supplier') . ')',
                    'amount'      => $advanceAmount, // debit
                    'created_by'  => Auth::id(),
                ]);
            }

            // CR Withholding Tax Payable — without this line the batch is short
            // by the WHT amount (AP is debited gross, bank is only credited net).
            if ((float) $voucher->withholding_tax_amount > 0) {
                GldTransaction::create([
                    'trans_no'    => $voucher->id,
                    'type'        => $glType,
                    'tran_date'   => $voucher->date_paid,
                    'account_code'=> $this->resolveWhtAccount($voucher),
                    'reference'   => $voucher->pvn_no,
                    'narration'   => 'Withholding tax withheld - ' . $voucher->pvn_no,
                    'amount'      => -$voucher->withholding_tax_amount, // credit
                    'created_by'  => Auth::id(),
                ]);
            }

            $voucher->update(['status' => 'posted']);
            DB::commit();

            try {
                broadcast(new DashboardEvent('purchases', 'payment_posted', [
                    'pvn_no' => $voucher->pvn_no,
                    'amount' => (float) $voucher->amount,
                ]));
            } catch (\Throwable $e) {
                Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }

            // ── Anchor on-chain (fire-and-forget) ────────────────────────────
            try {
                (new BlockchainService())->anchorPaymentVoucher(
                    voucherId:  $voucher->id,
                    pvnNo:      $voucher->pvn_no,
                    supplierId: (int) $voucher->supplier_id,
                    amount:     (float) $voucher->amount,
                    datePaid:   $voucher->date_paid->format('Y-m-d'),
                    type:       $voucher->type ?? 'bank',
                    createdBy:  (string) Auth::id(),
                );
            } catch (\Throwable $e) {
                Log::error('PaymentVoucher blockchain anchor failed: ' . $e->getMessage());
            }

            return ApiResponse::success($voucher->fresh(), 'Voucher posted to GL successfully');
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::validationError(['error' => $e->getMessage()]);
        }
    }

    // No dedicated WHT payable account is configured anywhere yet (WithholdingTax.gl_account
    // is blank on every seeded row, GlSetting.tax_deduction_account is null) — fall back to
    // the AP account so the line at least posts against something real, rather than a made-up
    // code. Configure WithholdingTax.gl_account or GlSetting.tax_deduction_account to fix this properly.
    private function resolveWhtAccount(PaymentVoucher $voucher): string
    {
        return $voucher->withholdingTax?->gl_account
            ?: GlSetting::first()?->tax_deduction_account
            ?: (GlSetting::first()?->payable_account ?: '201010');
    }

    /**
     * How much of a given open transaction is still unallocated — mirrors the
     * per-row left_to_allocate computation in openTransactions(), recomputed
     * server-side so a client can't post an allocation larger than what's
     * actually still owed on that specific invoice/GRN/credit note.
     */
    private function maxAllocatable(string $transactionType, int $transactionId, ?int $excludeAllocationId = null): float
    {
        $alreadyAllocated = (float) PaymentVoucherAllocation::where('transaction_type', $transactionType)
            ->where('transaction_id', $transactionId)
            ->when($excludeAllocationId, fn ($q) => $q->where('id', '!=', $excludeAllocationId))
            ->sum('this_allocation');

        if ($transactionType === 'Credit Note') {
            $total = (float) DB::table('supplier_credit_notes')->where('id', $transactionId)->value('total');
        } else {
            $total = (float) DB::table('purchase_orders')->where('id', $transactionId)->value('amount_total');
        }

        return max(0, $total - $alreadyAllocated);
    }

    /**
     * POST /purchases/payment-vouchers/{id}/correct-wht-gl
     * One-off repair for vouchers posted before the WHT GL line existed
     * (see resolveWhtAccount / post()) — inserts the missing credit line so
     * the batch balances. Safe to call repeatedly: no-ops once balanced.
     */
    public function correctWithholdingTaxGl(int $id): JsonResponse
    {
        $voucher = PaymentVoucher::findOrFail($id);

        if ($voucher->status !== 'posted') {
            return ApiResponse::validationError(['status' => 'Voucher is not posted']);
        }
        if ((float) $voucher->withholding_tax_amount <= 0) {
            return ApiResponse::validationError(['amount' => 'Voucher has no withholding tax to correct']);
        }

        $glType = 22;

        $alreadyCorrected = GldTransaction::where('type', $glType)
            ->where('trans_no', $voucher->id)
            ->where('narration', 'like', 'Withholding tax withheld%')
            ->exists();

        if ($alreadyCorrected) {
            return ApiResponse::success(null, 'Voucher already has its withholding tax GL line');
        }

        GldTransaction::create([
            'trans_no'    => $voucher->id,
            'type'        => $glType,
            'tran_date'   => $voucher->date_paid,
            'account_code'=> $this->resolveWhtAccount($voucher),
            'reference'   => $voucher->pvn_no,
            'narration'   => 'Withholding tax withheld - ' . $voucher->pvn_no,
            'amount'      => -$voucher->withholding_tax_amount, // credit
            'created_by'  => Auth::id(),
        ]);

        return ApiResponse::success(null, 'Withholding tax GL line added — batch should now balance');
    }
}
