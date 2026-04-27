<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GlAccount;
use App\Models\GldTransaction;
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
            ->havingRaw('left_to_allocate > 0')
            ->get();
        $rows = $rows->concat($creditNotes);

        // Supplier invoices and direct GRNs
        $invoices = DB::table('purchase_orders as po')
            ->where('po.supplier_id', $supplierId)
            ->whereIn('po.type', ['grn', 'invoice'])
            ->whereIn('po.status', ['received', 'ceo_approved'])
            ->leftJoin('payment_voucher_allocations as pva', function ($j) {
                $j->on('pva.transaction_id', '=', 'po.id')
                  ->whereIn('pva.transaction_type', ['Supplier Invoice', 'GRN Receipt']);
            })
            ->select(
                DB::raw("CASE po.type WHEN 'grn' THEN 'GRN Receipt' ELSE 'Supplier Invoice' END as transaction_type"),
                'po.id',
                'po.po_no as supplier_ref',
                DB::raw('po.order_date as date'),
                DB::raw('NULL as due_date'),
                'po.amount_total as amount',
                DB::raw('COALESCE(SUM(pva.this_allocation),0) as other_allocations'),
                DB::raw('po.amount_total - COALESCE(SUM(pva.this_allocation),0) as left_to_allocate')
            )
            ->groupBy('po.id', 'po.type', 'po.po_no', 'po.order_date', 'po.amount_total')
            ->havingRaw('left_to_allocate > 0')
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

        $vouchers = PaymentVoucher::with('supplier')
            ->where('supplier_id', $request->supplier_id)
            ->where('status', 'ceo_approved')
            ->orderBy('date_paid')
            ->get()
            ->map(fn($v) => [
                'transaction_type' => 'Payment Voucher',
                'id'               => $v->id,
                'supplier_ref'     => $v->pvn_no,
                'date'             => $v->date_paid?->format('Y-m-d'),
                'due_date'         => null,
                'amount'           => $v->amount,
                'other_allocations'=> 0,
                'left_to_allocate' => $v->amount,
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
            'withholding_tax_amount' => 'nullable|numeric|min:0',
            'amount'                 => 'required|numeric|min:0',
            'cheque_no'              => 'nullable|string|max:60',
            'memo'                   => 'nullable|string',
            'allocations'            => 'nullable|array',
            'allocations.*.transaction_type' => 'required|string',
            'allocations.*.transaction_id'   => 'required|integer',
            'allocations.*.this_allocation'  => 'required|numeric|min:0',
        ]);

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
     * GET /purchases/payment-vouchers/{id}
     */
    public function show(int $id): JsonResponse
    {
        $voucher = PaymentVoucher::with(['supplier', 'allocations'])->findOrFail($id);

        // Resolve bank account name
        $bankAccount = null;
        if ($voucher->bank_account_code) {
            $bankAccount = DB::table('gl_accounts')
                ->where('account_code', $voucher->bank_account_code)
                ->select('account_code', 'account_name')
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

        $transNo = 'PV-' . $id;

        $glEntries = DB::table('gld_transactions as g')
            ->leftJoin('gl_accounts as a', 'a.account_code', '=', 'g.account_code')
            ->where('g.trans_no', $transNo)
            ->select(
                'g.account_code',
                'a.account_name',
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
     */
    public function post(int $id): JsonResponse
    {
        $voucher = PaymentVoucher::with('allocations')->findOrFail($id);

        if (!in_array($voucher->status, ['ceo_approved', 'finance_approved', 'payables_approved', 'draft'])) {
            return ApiResponse::validationError(['status' => 'Voucher already posted']);
        }

        DB::beginTransaction();
        try {
            $transNo = 'PV-' . $voucher->id;

            // CR Bank/Cash account (payment going out)
            GldTransaction::create([
                'trans_no'   => $transNo,
                'type'       => 'payment_voucher',
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
                    'trans_no'    => $transNo,
                    'type'        => 'payment_voucher',
                    'tran_date'   => $voucher->date_paid,
                    'account_code'=> '', // AP account — ideally from GL settings
                    'reference'   => $voucher->pvn_no,
                    'narration'   => 'Supplier payment allocation - ' . $alloc->transaction_type . ' #' . $alloc->transaction_id,
                    'amount'      => $alloc->this_allocation, // debit
                    'created_by'  => Auth::id(),
                ]);
            }

            $voucher->update(['status' => 'posted']);
            DB::commit();

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
}
