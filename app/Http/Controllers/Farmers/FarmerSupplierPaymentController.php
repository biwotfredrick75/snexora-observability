<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FarmerPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FarmerSupplierPaymentController extends Controller
{
    public function formData(Request $request): JsonResponse
    {
        $search = $request->input('search', '');
        $farmersQuery = DB::table('farmers')
            ->where('status', 'active')
            ->orderBy('full_name');

        if ($search) {
            $farmersQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('farmer_no', 'like', "%{$search}%");
            });
        }

        $farmers = $farmersQuery->limit(100)->get(['id', 'farmer_no', 'full_name', 'account_no', 'bank_id']);

        $withholdingTaxes = DB::table('withholding_taxes')
            ->where('inactive', 0)
            ->orderBy('description')
            ->get(['id', 'description', 'tax_rate as rate']);

        $bankAccounts = DB::table('gl_accounts')
            ->where('inactive', 0)
            ->orderBy('name')
            ->limit(200)
            ->get(['code as account_code', 'name as account_name']);

        return ApiResponse::success(
            compact('farmers', 'withholdingTaxes', 'bankAccounts'),
            'Form data'
        );
    }

    // ── GL account running balance ─────────────────────────────────────────────
    //  GET /farmers/supplier-payments/account-balance?account_code=XXXX
    public function accountBalance(Request $request): JsonResponse
    {
        $request->validate(['account_code' => 'required|string']);

        $balance = (float) DB::table('gld_transactions')
            ->where('account_code', $request->account_code)
            ->sum('amount');

        $account = DB::table('gl_accounts')
            ->where('code', $request->account_code)
            ->select('code', 'name')
            ->first();

        return ApiResponse::success([
            'account_code' => $request->account_code,
            'account_name' => $account?->name,
            'balance'      => round($balance, 2),
        ], 'Balance retrieved');
    }

    // ── Available balance for advance validation ──────────────────────────────
    //  GET /farmers/supplier-payments/advance-limit?farmer_id=X&date=YYYY-MM-DD
    public function advanceLimit(Request $request): JsonResponse
    {
        $request->validate([
            'farmer_id' => 'required|integer|exists:farmers,id',
            'date'      => 'required|date',
        ]);

        $farmerId = (int) $request->farmer_id;
        $date     = $request->date;

        $breakdown = $this->computeAvailableBalance($farmerId, $date);

        return ApiResponse::success($breakdown, 'Advance limit calculated');
    }

    public function index(Request $request): JsonResponse
    {
        $query = FarmerPayment::with('farmer')
            ->orderByDesc('date_paid');

        if ($request->filled('farmer_id')) {
            $query->where('farmer_id', $request->farmer_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('from_date')) {
            $query->where('date_paid', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('date_paid', '<=', $request->to_date);
        }

        return ApiResponse::success($query->limit(200)->get(), 'Payments retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'farmer_id'            => 'required|integer|exists:farmers,id',
            'from_account'         => 'nullable|string|max:60',
            'date_paid'            => 'required|date',
            'reference'            => 'nullable|string|max:60',
            'type'                 => 'required|in:advance,payment',
            'withholding_tax_rate' => 'nullable|numeric|min:0|max:100',
            'amount_discount'      => 'nullable|numeric|min:0',
            'amount_payment'       => 'required|numeric|min:0.01',
            'bank_charge'          => 'nullable|numeric|min:0',
            'cheque_no'            => 'nullable|string|max:60',
            'memo'                 => 'nullable|string',
        ]);

        // ── Advance limit guard ───────────────────────────────────────────────
        if ($data['type'] === 'advance') {
            $breakdown = $this->computeAvailableBalance($data['farmer_id'], $data['date_paid']);
            $newTotal  = $breakdown['existing_advances'] + (float) $data['amount_payment'];

            if ($newTotal > $breakdown['milk_value'] - $breakdown['deductions']
                         - $breakdown['direct_invoices'] - $breakdown['carry_forward'] + 0.005) {
                return ApiResponse::validationError([
                    'amount_payment' => [
                        'Advance of ' . number_format($data['amount_payment'], 2)
                        . ' exceeds available balance of ' . number_format($breakdown['available'], 2)
                        . ' (Milk: ' . number_format($breakdown['milk_value'], 2)
                        . ', Existing advances: ' . number_format($breakdown['existing_advances'], 2)
                        . ', Deductions: ' . number_format($breakdown['deductions'], 2)
                        . ', Invoices: ' . number_format($breakdown['direct_invoices'], 2)
                        . ', Carry-forward: ' . number_format($breakdown['carry_forward'], 2) . ')',
                    ],
                ]);
            }
        }

        $data['created_by'] = $request->user()?->real_name ?? $request->user()?->user_id ?? 'system';

        $payment = DB::transaction(function () use ($data, $request) {
            $payment = FarmerPayment::create($data);

            // ── Post to GL ────────────────────────────────────────────────────
            $glSettings  = DB::table('gl_settings')->first();
            $transNo     = 'FP-' . $payment->id;
            $createdBy   = $request->user()?->user_id ?? 'system';
            $amount      = (float) $data['amount_payment'];
            $bankCharge  = (float) ($data['bank_charge'] ?? 0);
            $bankCode    = $data['from_account'] ?? ($glSettings->farmers_bank_account ?? '');
            $payableCode = $glSettings->farmers_payable_account ?? '';
            $chargeCode  = $glSettings->bank_charges_account    ?? '';
            $narration   = trim(
                ($data['type'] === 'advance' ? 'Farmer advance' : 'Farmer payment')
                . ($data['reference'] ? ' — ' . $data['reference'] : '')
                . ($data['memo'] ? ' | ' . $data['memo'] : '')
            );

            $glRows = [];

            // DR Farmers Payable (reduces liability to farmer)
            if ($payableCode) {
                $glRows[] = [
                    'trans_no'    => $transNo,
                    'type'        => 'farmer_payment',
                    'tran_date'   => $data['date_paid'],
                    'account_code'=> $payableCode,
                    'reference'   => $data['reference'] ?? $transNo,
                    'narration'   => $narration,
                    'amount'      => $amount,        // positive = debit
                    'created_by'  => $createdBy,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }

            // CR Bank Account (cash going out)
            if ($bankCode) {
                $glRows[] = [
                    'trans_no'    => $transNo,
                    'type'        => 'farmer_payment',
                    'tran_date'   => $data['date_paid'],
                    'account_code'=> $bankCode,
                    'reference'   => $data['reference'] ?? $transNo,
                    'narration'   => $narration,
                    'amount'      => -($amount + $bankCharge), // negative = credit
                    'created_by'  => $createdBy,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }

            // DR Bank Charges (expense)
            if ($bankCharge > 0 && $chargeCode) {
                $glRows[] = [
                    'trans_no'    => $transNo,
                    'type'        => 'farmer_payment',
                    'tran_date'   => $data['date_paid'],
                    'account_code'=> $chargeCode,
                    'reference'   => $data['reference'] ?? $transNo,
                    'narration'   => 'Bank charge — ' . $narration,
                    'amount'      => $bankCharge,    // positive = debit
                    'created_by'  => $createdBy,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }

            if (!empty($glRows)) {
                DB::table('gld_transactions')->insert($glRows);
            }

            return $payment;
        });

        return ApiResponse::created(
            $payment->load('farmer'),
            'Payment recorded and GL entries posted'
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Compute how much advance balance is available for a farmer in the month
    // that contains $date.
    // ─────────────────────────────────────────────────────────────────────────
    private function computeAvailableBalance(int $farmerId, string $date): array
    {
        $month     = (int) date('m', strtotime($date));
        $year      = (int) date('Y', strtotime($date));
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));

        // Milk value from submitted/approved purchases this period
        $milkValue = (float) (DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
            ->where('mpi.farmer_id', $farmerId)
            ->whereBetween('mp.invoice_date', [$startDate, $endDate])
            ->whereIn('mp.status', ['submitted', 'approved'])
            ->sum('mpi.total_price') ?? 0);

        // Advances already recorded this period
        $existingAdvances = (float) (DB::table('farmer_payments')
            ->where('farmer_id', $farmerId)
            ->where('type', 'advance')
            ->whereBetween('date_paid', [$startDate, $endDate])
            ->sum('amount_payment') ?? 0);

        // Checkoff deductions for this month
        $deductions = (float) (DB::table('farmer_checkoff_entries')
            ->where('farmer_id', $farmerId)
            ->where('month', $month)
            ->where('year', $year)
            ->sum('amount') ?? 0);

        // Placed direct invoices falling in this period
        $directInvoices = (float) (DB::table('farmer_direct_invoices')
            ->where('farmer_id', $farmerId)
            ->where('status', 'placed')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->sum('amount_total') ?? 0);

        // Carry-forward debt from previous period (positive = farmer owes)
        $carryForward = (float) (DB::table('farmer_carry_forwards')
            ->where('farmer_id', $farmerId)
            ->where('to_month', $month)
            ->where('to_year', $year)
            ->value('amount') ?? 0);

        $available = $milkValue - $existingAdvances - $deductions - $directInvoices - $carryForward;

        return [
            'month'            => $month,
            'year'             => $year,
            'milk_value'       => round($milkValue, 2),
            'existing_advances'=> round($existingAdvances, 2),
            'deductions'       => round($deductions, 2),
            'direct_invoices'  => round($directInvoices, 2),
            'carry_forward'    => round($carryForward, 2),
            'available'        => round(max(0, $available), 2),
        ];
    }
}
