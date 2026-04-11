<?php

namespace App\Http\Controllers\Sales;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerPayment;
use App\Models\DebtorAllocation;
use App\Models\GldTransaction;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Services\Blockchain\BlockchainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerPaymentController extends Controller
{
    // TYPE constant reused for GL entries
    private const TYPE_PAYMENT = 12;

    // ── Unallocated payments for a customer ──────────────────────────────────

    public function unallocated(Request $request): JsonResponse
    {
        $debtorNo = $request->get('debtor_no');
        if (! $debtorNo) {
            return ApiResponse::validationError(['debtor_no' => ['Required']]);
        }

        $payments = CustomerPayment::where('debtor_no', $debtorNo)
            ->where('status', 'posted')
            ->where('unallocated_amount', '>', 0)
            ->orderBy('payment_date')
            ->get();

        return ApiResponse::success($payments, 'Unallocated payments retrieved');
    }

    // ── Manual allocation of a payment to specific invoices ───────────────────

    public function allocateManual(int $id, Request $request): JsonResponse
    {
        $payment = CustomerPayment::find($id);
        if (! $payment) return ApiResponse::notFound('Payment not found');
        if ($payment->status !== 'posted') return ApiResponse::error('Only posted payments can be allocated', 422);

        $unallocated = round((float) $payment->unallocated_amount, 2);
        if ($unallocated <= 0) return ApiResponse::error('Payment has no unallocated balance', 422);

        $request->validate([
            'allocations'          => 'required|array|min:1',
            'allocations.*.inv_id' => 'required|integer|exists:sales_invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $totalRequested = round(array_sum(array_column($request->allocations, 'amount')), 2);
        if ($totalRequested > $unallocated) {
            return ApiResponse::validationError(['allocations' => [
                'Total allocation (' . $totalRequested . ') exceeds unallocated balance (' . $unallocated . ')'
            ]]);
        }

        // Pre-validate each invoice before touching the DB
        $errors = [];
        foreach ($request->allocations as $alloc) {
            $invoice = SalesInvoice::find($alloc['inv_id']);
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
        if (! empty($errors)) {
            return ApiResponse::validationError(['allocations' => $errors]);
        }

        return DB::transaction(function () use ($payment, $request) {
            $createdBy    = auth()->user()?->user_id ?? 'system';
            $allocDate    = now()->toDateString();
            $totalApplied = 0.0;

            foreach ($request->allocations as $alloc) {
                $allocAmt = (float) $alloc['amount'];
                if ($allocAmt <= 0) continue;

                $invoice = SalesInvoice::lockForUpdate()->find($alloc['inv_id']);
                if (! $invoice) continue;

                $alreadyAlloc = (float) DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
                $leftToAlloc  = max(0, round($invoice->amount_total - $alreadyAlloc, 2));
                if ($leftToAlloc <= 0) continue;
                $actual = round(min($allocAmt, $leftToAlloc), 2);

                DebtorAllocation::create([
                    'debtor_no'      => $payment->debtor_no,
                    'source_type'    => 'payment',
                    'source_id'      => $payment->id,
                    'source_no'      => $payment->payment_no,
                    'inv_id'         => $invoice->id,
                    'inv_no'         => $invoice->inv_no,
                    'amount'         => $actual,
                    'allocated_date' => $allocDate,
                    'created_by'     => $createdBy,
                ]);

                $totalApplied += $actual;
            }

            if ($totalApplied <= 0) {
                return ApiResponse::error('No allocations could be applied', 422);
            }

            $payment->allocated_amount   = round($payment->allocated_amount + $totalApplied, 2);
            $payment->unallocated_amount = round($payment->unallocated_amount - $totalApplied, 2);
            $payment->save();

            return ApiResponse::success(
                $payment->fresh(['customer', 'allocations.invoice']),
                'Allocated KES ' . number_format($totalApplied, 2) . ' to invoices'
            );
        });
    }

    // ── Unpaid invoices for a customer ───────────────────────────────────────

    public function unpaidInvoices(Request $request): JsonResponse
    {
        $debtorNo = $request->get('debtor_no');
        if (! $debtorNo) {
            return ApiResponse::validationError(['debtor_no' => ['Required']]);
        }

        $query = SalesInvoice::with('customer:debtor_no,name')
            ->where('debtor_no', $debtorNo)
            ->where('status', 'placed')
            ->orderBy('invoice_date');

        if ($v = $request->get('date_from')) $query->where('invoice_date', '>=', $v);
        if ($v = $request->get('date_to'))   $query->where('invoice_date', '<=', $v);

        $invoices = $query->get();

        $result = $invoices->map(function ($inv) {
            $otherAlloc = (float) DebtorAllocation::where('inv_id', $inv->id)->sum('amount');
            $leftToAlloc = max(0, round($inv->amount_total - $otherAlloc, 2));
            return [
                'id'               => $inv->id,
                'inv_no'           => $inv->inv_no,
                'invoice_date'     => $inv->invoice_date,
                'due_date'         => $inv->due_date,
                'amount_total'     => (float) $inv->amount_total,
                'other_allocations'=> round($otherAlloc, 2),
                'left_to_allocate' => $leftToAlloc,
                'debtor_no'        => $inv->debtor_no,
                'customer_name'    => $inv->customer?->name,
            ];
        })->filter(fn ($inv) => $inv['left_to_allocate'] > 0)->values();

        return ApiResponse::success($result, 'Unpaid invoices retrieved');
    }

    // ── List payments ─────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = CustomerPayment::with('customer:debtor_no,name')->orderByDesc('id');

        if ($v = $request->get('debtor_no'))  $query->where('debtor_no', $v);
        if ($v = $request->get('date_from'))  $query->where('payment_date', '>=', $v);
        if ($v = $request->get('date_to'))    $query->where('payment_date', '<=', $v);
        if ($v = $request->get('status'))     $query->where('status', $v);

        $payments = $query->paginate(min((int) $request->get('per_page', 20), 100));

        return ApiResponse::paginated($payments, 'Payments retrieved');
    }

    // ── Show single payment ───────────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $payment = CustomerPayment::with([
            'customer',
            'allocations' => fn ($q) => $q->orderBy('allocated_date'),
            'allocations.invoice:id,inv_no,invoice_date,due_date,amount_total',
        ])->find($id);
        if (! $payment) return ApiResponse::notFound('Payment not found');
        return ApiResponse::success($payment, 'Payment retrieved');
    }

    // ── Store (create + post GL + allocate) ───────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'debtor_no'        => 'required|string|max:10|exists:customers,debtor_no',
            'payment_date'     => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'bank_account_code'=> 'nullable|string|max:20',
            'payment_type'     => 'nullable|string|max:20',
            'amount'           => 'required|numeric|min:0.01',
            'discount_amount'  => 'nullable|numeric|min:0',
            'bank_charge'      => 'nullable|numeric|min:0',
            'reference'        => 'nullable|string|max:60',
            'memo'             => 'nullable|string',
            // allocations: [{inv_id, amount}]
            'allocations'          => 'nullable|array',
            'allocations.*.inv_id' => 'required_with:allocations|integer|exists:sales_invoices,id',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:0.01',
        ]);

        return DB::transaction(function () use ($validated) {
            $createdBy    = auth()->user()?->user_id ?? 'system';
            $paymentDate  = $validated['payment_date'];
            $amount       = (float) $validated['amount'];
            $discountAmt  = (float) ($validated['discount_amount'] ?? 0);
            $bankCharge   = (float) ($validated['bank_charge'] ?? 0);
            $allocations  = $validated['allocations'] ?? [];

            // Validate that total allocations ≤ payment amount
            $totalAlloc = array_sum(array_column($allocations, 'amount'));
            if (round($totalAlloc, 2) > round($amount, 2)) {
                return ApiResponse::validationError(['allocations' => [
                    'Total allocation (' . $totalAlloc . ') exceeds payment amount (' . $amount . ')'
                ]]);
            }

            // Create payment record
            $payment = CustomerPayment::create([
                'payment_no'       => 'TEMP-' . uniqid(),
                'debtor_no'        => $validated['debtor_no'],
                'payment_date'     => $paymentDate,
                'bank_account_code'=> $validated['bank_account_code'] ?? null,
                'payment_type'     => $validated['payment_type'] ?? 'normal',
                'amount'           => $amount,
                'discount_amount'  => $discountAmt,
                'bank_charge'      => $bankCharge,
                'allocated_amount' => 0,
                'unallocated_amount' => $amount,
                'reference'        => $validated['reference'] ?? null,
                'memo'             => $validated['memo'] ?? null,
                'status'           => 'posted',
                'created_by'       => $createdBy,
            ]);

            $payment->update(['payment_no' => CustomerPayment::nextPaymentNo()]);
            $paymentNo = $payment->payment_no;

            // ── Invoice allocations ─────────────────────────────────────────
            $totalAllocated = 0.0;

            foreach ($allocations as $alloc) {
                $allocAmt = (float) $alloc['amount'];
                if ($allocAmt <= 0) continue;

                $invoice = SalesInvoice::find($alloc['inv_id']);
                if (! $invoice) continue;

                // Ensure we don't over-allocate the invoice
                $alreadyAlloc = (float) DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
                $leftToAlloc  = max(0, round($invoice->amount_total - $alreadyAlloc, 2));
                $actualAlloc  = min($allocAmt, $leftToAlloc);

                if ($actualAlloc <= 0) continue;

                DebtorAllocation::create([
                    'debtor_no'      => $payment->debtor_no,
                    'source_type'    => 'payment',
                    'source_id'      => $payment->id,
                    'source_no'      => $paymentNo,
                    'inv_id'         => $invoice->id,
                    'inv_no'         => $invoice->inv_no,
                    'amount'         => $actualAlloc,
                    'allocated_date' => $paymentDate,
                    'created_by'     => $createdBy,
                ]);

                $totalAllocated += $actualAlloc;
            }

            // Update payment totals
            $payment->allocated_amount   = round($totalAllocated, 2);
            $payment->unallocated_amount = round($amount - $totalAllocated, 2);
            $payment->save();

            // ── GL Entries ──────────────────────────────────────────────────
            $glSetting      = DB::table('gl_settings')->first();
            $companyPref    = DB::table('company_preferences')->first();
            $debtorsAccount = ($companyPref->debtors_gl_code ?? null) ?: ($glSetting->receivable_account ?? null) ?: 'DEBTORS';
            $bankAccount    = $payment->bank_account_code ?: ($glSetting->receivable_account ?? 'BANK');
            $bankChargesAcc = $glSetting->bank_charges_account ?? 'BANK_CHARGES';
            $discountAcc    = $glSetting->prompt_payment_discount_account ?? ($glSetting->sales_discount_account ?? 'PPD');

            // DR Bank (full payment amount)
            $this->gl($payment->id, $paymentDate, $bankAccount, $amount,
                "Customer Payment — {$paymentNo}", $paymentNo, $createdBy);

            // CR Debtors (full payment + any discount)
            $totalCredit = $amount + $discountAmt;
            $this->gl($payment->id, $paymentDate, $debtorsAccount, -round($totalCredit, 2),
                "Customer Payment Debtors — {$paymentNo}", $paymentNo, $createdBy);

            // DR Prompt Payment Discount (if given)
            if ($discountAmt > 0) {
                $this->gl($payment->id, $paymentDate, $discountAcc, $discountAmt,
                    "Customer PPD — {$paymentNo}", $paymentNo, $createdBy);
            }

            // Bank charge: DR Bank Charges, CR Bank (nets out of bank)
            if ($bankCharge > 0) {
                $this->gl($payment->id, $paymentDate, $bankChargesAcc, $bankCharge,
                    "Bank Charge — {$paymentNo}", $paymentNo, $createdBy);
                $this->gl($payment->id, $paymentDate, $bankAccount, -$bankCharge,
                    "Bank Charge Deduction — {$paymentNo}", $paymentNo, $createdBy);
            }

            broadcast(new DashboardEvent('payments', 'payment_posted'));

            // ── Anchor on-chain (fire-and-forget — never blocks the response) ──
            try {
                (new BlockchainService())->anchorCustomerPayment(
                    paymentId:   $payment->id,
                    paymentNo:   $payment->payment_no,
                    debtorNo:    $payment->debtor_no,
                    amount:      (float) $payment->amount,
                    paymentDate: $payment->payment_date,
                    paymentType: $payment->payment_type ?? 'normal',
                    createdBy:   $createdBy,
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('CustomerPayment blockchain anchor failed: ' . $e->getMessage());
            }

            return ApiResponse::created($payment->load('allocations'), 'Payment posted successfully');
        });
    }

    // ── Allocate unallocated balance to outstanding invoices (FIFO) ───────────

    public function allocate(int $id): JsonResponse
    {
        $payment = CustomerPayment::find($id);
        if (! $payment) return ApiResponse::notFound('Payment not found');
        if ($payment->status !== 'posted') return ApiResponse::error('Only posted payments can be allocated', 422);

        $unallocated = round((float) $payment->unallocated_amount, 2);
        if ($unallocated <= 0) return ApiResponse::error('Payment has no unallocated balance', 422);

        return DB::transaction(function () use ($payment, $unallocated) {
            $createdBy = auth()->user()?->user_id ?? 'system';
            $allocDate = now()->toDateString();

            // Fetch outstanding invoices FIFO
            $invoices = SalesInvoice::where('debtor_no', $payment->debtor_no)
                ->where('status', 'placed')
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remaining     = $unallocated;
            $totalApplied  = 0.0;

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) break;

                $alreadyAlloc = (float) DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
                $leftToAlloc  = round((float) $invoice->amount_total - $alreadyAlloc, 2);
                if ($leftToAlloc <= 0) continue;

                $use = min($remaining, $leftToAlloc);
                if ($use <= 0) continue;

                DebtorAllocation::create([
                    'debtor_no'      => $payment->debtor_no,
                    'source_type'    => 'payment',
                    'source_id'      => $payment->id,
                    'source_no'      => $payment->payment_no,
                    'inv_id'         => $invoice->id,
                    'inv_no'         => $invoice->inv_no,
                    'amount'         => round($use, 2),
                    'allocated_date' => $allocDate,
                    'created_by'     => $createdBy,
                ]);

                $remaining    -= $use;
                $totalApplied += $use;
            }

            if ($totalApplied <= 0) {
                return ApiResponse::error('No outstanding invoices found to allocate against', 422);
            }

            $payment->allocated_amount   = round($payment->allocated_amount + $totalApplied, 2);
            $payment->unallocated_amount = round($payment->unallocated_amount - $totalApplied, 2);
            $payment->save();

            $updated = CustomerPayment::with([
                'customer',
                'allocations' => fn ($q) => $q->orderBy('allocated_date'),
                'allocations.invoice:id,inv_no,invoice_date,due_date,amount_total',
            ])->find($payment->id);

            return ApiResponse::success($updated, "Allocated KES " . number_format($totalApplied, 2) . " across invoices");
        });
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function cancel(int $id): JsonResponse
    {
        $payment = CustomerPayment::find($id);
        if (! $payment) return ApiResponse::notFound('Payment not found');
        if ($payment->status === 'cancelled') return ApiResponse::error('Payment already cancelled', 422);
        if ($payment->allocated_amount > 0) {
            return ApiResponse::error('Cannot cancel a payment with allocations. Remove allocations first.', 422);
        }

        DB::transaction(function () use ($payment) {
            // Reverse GL entries
            GldTransaction::where('type', self::TYPE_PAYMENT)
                ->where('trans_no', $payment->id)
                ->delete();

            $payment->update(['status' => 'cancelled']);
        });

        return ApiResponse::success($payment, 'Payment cancelled');
    }

    // ── GL helper ────────────────────────────────────────────────────────────

    private function gl(int $transNo, string $tranDate, string $accountCode, float $amount, string $narration, string $reference, string $createdBy): void
    {
        GldTransaction::create([
            'trans_no'     => $transNo,
            'type'         => self::TYPE_PAYMENT,
            'tran_date'    => $tranDate,
            'account_code' => $accountCode,
            'reference'    => $reference,
            'narration'    => $narration,
            'amount'       => $amount,
            'created_by'   => $createdBy,
        ]);
    }
}
