<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerDeposit;
use App\Models\DebtorAllocation;
use App\Models\GldTransaction;
use App\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerDepositController extends Controller
{
    private const TYPE_DEPOSIT = 13;

    // ── List ─────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = CustomerDeposit::with('customer:debtor_no,name')->orderByDesc('id');

        if ($v = $request->get('debtor_no'))     $query->where('debtor_no', $v);
        if ($v = $request->get('date_from'))     $query->where('deposit_date', '>=', $v);
        if ($v = $request->get('date_to'))       $query->where('deposit_date', '<=', $v);
        if ($v = $request->get('status'))        $query->where('status', $v);
        if ($request->boolean('unallocated'))    $query->where('unallocated_amount', '>', 0);

        $deposits = $query->paginate(min((int) $request->get('per_page', 50), 200));

        return ApiResponse::paginated($deposits, 'Deposits retrieved');
    }

    // ── Unallocated deposits for a customer (for the allocation picker) ───────

    public function unallocated(Request $request): JsonResponse
    {
        $debtorNo = $request->get('debtor_no');
        if (! $debtorNo) {
            return ApiResponse::validationError(['debtor_no' => ['Required']]);
        }

        $deposits = CustomerDeposit::where('debtor_no', $debtorNo)
            ->where('status', 'posted')
            ->where('unallocated_amount', '>', 0)
            ->orderBy('deposit_date')
            ->get();

        return ApiResponse::success($deposits, 'Unallocated deposits retrieved');
    }

    // ── Show ─────────────────────────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $deposit = CustomerDeposit::with(['customer', 'allocations.invoice:id,inv_no,invoice_date,due_date,amount_total'])->find($id);
        if (! $deposit) return ApiResponse::notFound('Deposit not found');
        return ApiResponse::success($deposit, 'Deposit retrieved');
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'debtor_no'          => 'required|string|max:10|exists:customers,debtor_no',
            'bank_account_code'  => 'nullable|string|max:20',
            'deposit_date'       => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'deposit_amount'     => 'required|numeric|min:0.01',
            'reference'          => 'nullable|string|max:60',
            'external_reference' => 'nullable|string|max:60',
            'branch'             => 'nullable|string|max:100',
            'memo'               => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated) {
            $createdBy   = auth()->user()?->user_id ?? 'system';
            $depositDate = $validated['deposit_date'];
            $amount      = (float) $validated['deposit_amount'];

            $deposit = CustomerDeposit::create([
                'deposit_no'         => 'TEMP-' . uniqid(),
                'debtor_no'          => $validated['debtor_no'],
                'bank_account_code'  => $validated['bank_account_code'] ?? null,
                'deposit_date'       => $depositDate,
                'deposit_amount'     => $amount,
                'allocated_amount'   => 0,
                'unallocated_amount' => $amount,
                'reference'          => $validated['reference'] ?? null,
                'external_reference' => $validated['external_reference'] ?? null,
                'branch'             => $validated['branch'] ?? null,
                'memo'               => $validated['memo'] ?? null,
                'status'             => 'posted',
                'created_by'         => $createdBy,
            ]);

            $deposit->update(['deposit_no' => CustomerDeposit::nextDepositNo()]);
            $depositNo = $deposit->deposit_no;

            // ── GL Entries ──────────────────────────────────────────────────
            $glSetting      = DB::table('gl_settings')->first();
            $companyPref    = DB::table('company_preferences')->first();
            $debtorsAccount = ($companyPref->debtors_gl_code ?? null) ?: ($glSetting->receivable_account ?? null) ?: 'DEBTORS';
            // No bank account picked — there's no dedicated "default bank" GL
            // setting, so fall back to a placeholder rather than reusing the
            // receivables account (which is unrelated to cash/bank).
            $bankAccount    = $deposit->bank_account_code ?: 'BANK';

            // DR Bank
            $this->gl($deposit->id, $depositDate, $bankAccount, $amount,
                "Customer Deposit — {$depositNo}", $depositNo, $createdBy);

            // CR Debtors
            $this->gl($deposit->id, $depositDate, $debtorsAccount, -$amount,
                "Customer Deposit Debtors — {$depositNo}", $depositNo, $createdBy);

            return ApiResponse::created($deposit->load('customer'), 'Deposit posted successfully');
        });
    }

    // ── Allocate deposit to invoices ──────────────────────────────────────────

    public function allocate(int $id, Request $request): JsonResponse
    {
        $deposit = CustomerDeposit::find($id);
        if (! $deposit) return ApiResponse::notFound('Deposit not found');
        if ($deposit->status !== 'posted') return ApiResponse::error('Only posted deposits can be allocated', 422);

        $unallocated = round((float) $deposit->unallocated_amount, 2);
        if ($unallocated <= 0) return ApiResponse::error('Deposit has no unallocated balance', 422);

        $request->validate([
            'allocations'          => 'required|array|min:1',
            'allocations.*.inv_id' => 'required|integer|exists:sales_invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ]);

        $totalRequested = array_sum(array_column($request->allocations, 'amount'));
        if (round($totalRequested, 2) > $unallocated) {
            return ApiResponse::validationError(['allocations' => [
                'Total allocation (' . $totalRequested . ') exceeds unallocated balance (' . $unallocated . ')'
            ]]);
        }

        // Pre-validate each invoice: check for fully-paid invoices before touching DB
        $errors = [];
        foreach ($request->allocations as $idx => $alloc) {
            $invoice = SalesInvoice::find($alloc['inv_id']);
            if (! $invoice) {
                $errors[] = "Invoice #{$alloc['inv_id']} not found.";
                continue;
            }
            $alreadyAlloc = (float) DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
            $leftToAlloc  = max(0, round($invoice->amount_total - $alreadyAlloc, 2));
            if ($leftToAlloc <= 0) {
                $errors[] = "Invoice {$invoice->inv_no} is already fully paid — cannot allocate to it.";
            } elseif (round((float) $alloc['amount'], 2) > $leftToAlloc) {
                $errors[] = "Invoice {$invoice->inv_no}: requested " . number_format($alloc['amount'], 2) .
                            " exceeds remaining balance " . number_format($leftToAlloc, 2) . ".";
            }
        }
        if (! empty($errors)) {
            return ApiResponse::validationError(['allocations' => $errors]);
        }

        return DB::transaction(function () use ($deposit, $request, $unallocated) {
            $createdBy  = auth()->user()?->user_id ?? 'system';
            $allocDate  = now()->toDateString();
            $totalApplied = 0.0;

            foreach ($request->allocations as $alloc) {
                $allocAmt = (float) $alloc['amount'];
                if ($allocAmt <= 0) continue;

                $invoice = SalesInvoice::lockForUpdate()->find($alloc['inv_id']);
                if (! $invoice) continue;

                $alreadyAlloc = (float) DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
                $leftToAlloc  = max(0, round($invoice->amount_total - $alreadyAlloc, 2));
                if ($leftToAlloc <= 0) continue; // race-condition safety: skip if fully paid by now
                $actual = round(min($allocAmt, $leftToAlloc), 2);

                DebtorAllocation::create([
                    'debtor_no'      => $deposit->debtor_no,
                    'source_type'    => 'deposit',
                    'source_id'      => $deposit->id,
                    'source_no'      => $deposit->deposit_no,
                    'inv_id'         => $invoice->id,
                    'inv_no'         => $invoice->inv_no,
                    'amount'         => round($actual, 2),
                    'allocated_date' => $allocDate,
                    'created_by'     => $createdBy,
                ]);

                $totalApplied += $actual;
            }

            if ($totalApplied <= 0) {
                return ApiResponse::error('No allocations could be applied', 422);
            }

            $deposit->allocated_amount   = round($deposit->allocated_amount + $totalApplied, 2);
            $deposit->unallocated_amount = round($deposit->unallocated_amount - $totalApplied, 2);
            $deposit->save();

            return ApiResponse::success(
                $deposit->fresh(['customer', 'allocations.invoice']),
                'Allocated KES ' . number_format($totalApplied, 2) . ' to invoices'
            );
        });
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function cancel(int $id): JsonResponse
    {
        $deposit = CustomerDeposit::find($id);
        if (! $deposit) return ApiResponse::notFound('Deposit not found');
        if ($deposit->status === 'cancelled') return ApiResponse::error('Deposit already cancelled', 422);
        if ($deposit->allocated_amount > 0) {
            return ApiResponse::error('Cannot cancel a deposit with allocations. Remove allocations first.', 422);
        }

        DB::transaction(function () use ($deposit) {
            GldTransaction::where('type', self::TYPE_DEPOSIT)
                ->where('trans_no', $deposit->id)
                ->delete();
            $deposit->update(['status' => 'cancelled']);
        });

        return ApiResponse::success($deposit, 'Deposit cancelled');
    }

    // ── GL helper ─────────────────────────────────────────────────────────────

    private function gl(int $transNo, string $tranDate, string $accountCode, float $amount, string $narration, string $reference, string $createdBy): void
    {
        GldTransaction::create([
            'trans_no'     => $transNo,
            'type'         => self::TYPE_DEPOSIT,
            'tran_date'    => $tranDate,
            'account_code' => $accountCode,
            'reference'    => $reference,
            'narration'    => $narration,
            'amount'       => $amount,
            'created_by'   => $createdBy,
        ]);
    }
}
