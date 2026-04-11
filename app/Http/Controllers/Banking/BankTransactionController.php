<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankTransactionController extends Controller
{
    // ── GL type codes ─────────────────────────────────────────────────────────
    private const TYPE_PAYMENT  = 2;   // bank payment
    private const TYPE_DEPOSIT  = 1;   // bank deposit
    private const TYPE_TRANSFER = 4;   // funds transfer

    // ─────────────────────────────────────────────────────────────────────────
    // Shared form data
    // ─────────────────────────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        // Bank accounts = GL group code 1021
        $bankAccounts = DB::table('gl_accounts')
            ->join('gl_account_groups', 'gl_accounts.group_id', '=', 'gl_account_groups.id')
            ->where('gl_account_groups.code', '1021')
            ->where('gl_accounts.inactive', false)
            ->orderBy('gl_accounts.code')
            ->get(['gl_accounts.code', 'gl_accounts.name']);

        // All active GL accounts for line items
        $glAccounts = DB::table('gl_accounts')
            ->where('inactive', false)
            ->orderBy('code')
            ->get(['code', 'name']);

        $dimensions = DB::table('dimensions')
            ->where('inactive', false)
            ->orderBy('name')
            ->get(['id', 'name', 'reference']);

        $taxTypes = DB::table('tax_types')
            ->where('inactive', false)
            ->orderBy('id')
            ->get(['id', 'description', 'default_rate']);

        return ApiResponse::success(compact('bankAccounts', 'glAccounts', 'dimensions', 'taxTypes'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BANK PAYMENTS
    // ─────────────────────────────────────────────────────────────────────────
    public function paymentsIndex(Request $request): JsonResponse
    {
        $q = DB::table('bank_payments')
            ->orderByDesc('payment_date')->orderByDesc('id');

        if ($request->filled('from')) $q->where('payment_date', '>=', $request->from);
        if ($request->filled('to'))   $q->where('payment_date', '<=', $request->to);
        if ($request->filled('bank')) $q->where('bank_account_code', $request->bank);

        $rows = $q->limit(500)->get();
        return ApiResponse::success($rows);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_account_code' => 'required|string',
            'pay_to'            => 'nullable|string|max:150',
            'payment_date'      => 'required|date',
            'reference'         => 'nullable|string|max:80',
            'cheque_no'         => 'nullable|string|max:40',
            'exchange_rate'     => 'nullable|numeric|min:0.0001',
            'memo'              => 'nullable|string',
            'items'             => 'required|array|min:1',
            'items.*.account_code'  => 'required|string',
            'items.*.amount'        => 'required|numeric|min:0.01',
            'items.*.narration'     => 'nullable|string|max:255',
            'items.*.dimension_id'  => 'nullable|integer',
            'items.*.dimension2_id' => 'nullable|integer',
            'items.*.tax_type_id'   => 'nullable|integer',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $payNo = $this->nextRef('bank_payment');
            $total = collect($data['items'])->sum('amount');
            $user  = $request->user()?->user_id ?? 'system';

            $payment = DB::table('bank_payments')->insertGetId([
                'pay_no'            => $payNo,
                'bank_account_code' => $data['bank_account_code'],
                'pay_to'            => $data['pay_to'] ?? null,
                'payment_date'      => $data['payment_date'],
                'reference'         => $data['reference'] ?? null,
                'cheque_no'         => $data['cheque_no'] ?? null,
                'exchange_rate'     => $data['exchange_rate'] ?? 1,
                'amount'            => $total,
                'memo'              => $data['memo'] ?? null,
                'status'            => 'posted',
                'created_by'        => $user,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // Line items
            $lineInserts = [];
            foreach ($data['items'] as $item) {
                $lineInserts[] = [
                    'payment_id'    => $payment,
                    'account_code'  => $item['account_code'],
                    'dimension_id'  => $item['dimension_id'] ?? null,
                    'dimension2_id' => $item['dimension2_id'] ?? null,
                    'tax_type_id'   => $item['tax_type_id'] ?? null,
                    'amount'        => $item['amount'],
                    'narration'     => $item['narration'] ?? null,
                ];
            }
            DB::table('bank_payment_items')->insert($lineInserts);

            // GL: CR bank account (negative = credit), DR each expense account (positive)
            $transNo = $this->nextGlTransNo();
            $glLines = [];

            // Credit the bank account for total
            $glLines[] = $this->glLine(self::TYPE_PAYMENT, $transNo, $data['payment_date'],
                $data['bank_account_code'], -$total, $payNo,
                $data['memo'] ?? ('Payment to '.($data['pay_to'] ?? '')), $user);

            // Debit each line account
            foreach ($data['items'] as $item) {
                $glLines[] = $this->glLine(self::TYPE_PAYMENT, $transNo, $data['payment_date'],
                    $item['account_code'], $item['amount'], $payNo,
                    $item['narration'] ?? ($data['memo'] ?? ''), $user,
                    $item['dimension_id'] ?? null, $item['dimension2_id'] ?? null);
            }

            DB::table('gld_transactions')->insert($glLines);

            return ApiResponse::created(['id' => $payment, 'pay_no' => $payNo, 'amount' => $total], 'Payment posted');
        });
    }

    public function voidPayment(int $id): JsonResponse
    {
        $pay = DB::table('bank_payments')->find($id);
        if (! $pay) return ApiResponse::notFound('Payment not found');
        if ($pay->status === 'voided') return ApiResponse::validationError(['status' => 'Already voided']);

        return DB::transaction(function () use ($pay) {
            DB::table('bank_payments')->where('id', $pay->id)->update(['status' => 'voided', 'updated_at' => now()]);
            $this->reverseGl(self::TYPE_PAYMENT, $pay->pay_no, now()->toDateString());
            return ApiResponse::success(null, 'Payment voided');
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BANK DEPOSITS
    // ─────────────────────────────────────────────────────────────────────────
    public function depositsIndex(Request $request): JsonResponse
    {
        $q = DB::table('bank_deposits')->orderByDesc('deposit_date')->orderByDesc('id');
        if ($request->filled('from')) $q->where('deposit_date', '>=', $request->from);
        if ($request->filled('to'))   $q->where('deposit_date', '<=', $request->to);
        if ($request->filled('bank')) $q->where('bank_account_code', $request->bank);
        return ApiResponse::success($q->limit(500)->get());
    }

    public function storeDeposit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_account_code' => 'required|string',
            'deposit_type'      => 'nullable|string|max:60',
            'deposit_date'      => 'required|date',
            'reference'         => 'nullable|string|max:80',
            'cheque_no'         => 'nullable|string|max:40',
            'exchange_rate'     => 'nullable|numeric|min:0.0001',
            'memo'              => 'nullable|string',
            'items'             => 'required|array|min:1',
            'items.*.account_code'  => 'required|string',
            'items.*.amount'        => 'required|numeric|min:0.01',
            'items.*.narration'     => 'nullable|string|max:255',
            'items.*.dimension_id'  => 'nullable|integer',
            'items.*.dimension2_id' => 'nullable|integer',
            'items.*.tax_type_id'   => 'nullable|integer',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $depNo = $this->nextRef('bank_deposit');
            $total = collect($data['items'])->sum('amount');
            $user  = $request->user()?->user_id ?? 'system';

            $deposit = DB::table('bank_deposits')->insertGetId([
                'dep_no'            => $depNo,
                'bank_account_code' => $data['bank_account_code'],
                'deposit_type'      => $data['deposit_type'] ?? 'Miscellaneous',
                'deposit_date'      => $data['deposit_date'],
                'reference'         => $data['reference'] ?? null,
                'cheque_no'         => $data['cheque_no'] ?? null,
                'exchange_rate'     => $data['exchange_rate'] ?? 1,
                'amount'            => $total,
                'memo'              => $data['memo'] ?? null,
                'status'            => 'posted',
                'created_by'        => $user,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $lineInserts = [];
            foreach ($data['items'] as $item) {
                $lineInserts[] = [
                    'deposit_id'    => $deposit,
                    'account_code'  => $item['account_code'],
                    'dimension_id'  => $item['dimension_id'] ?? null,
                    'dimension2_id' => $item['dimension2_id'] ?? null,
                    'tax_type_id'   => $item['tax_type_id'] ?? null,
                    'amount'        => $item['amount'],
                    'narration'     => $item['narration'] ?? null,
                ];
            }
            DB::table('bank_deposit_items')->insert($lineInserts);

            // GL: DR bank account (positive = debit), CR each income/other account (negative)
            $transNo = $this->nextGlTransNo();
            $glLines = [];

            $glLines[] = $this->glLine(self::TYPE_DEPOSIT, $transNo, $data['deposit_date'],
                $data['bank_account_code'], $total, $depNo,
                $data['memo'] ?? 'Deposit', $user);

            foreach ($data['items'] as $item) {
                $glLines[] = $this->glLine(self::TYPE_DEPOSIT, $transNo, $data['deposit_date'],
                    $item['account_code'], -$item['amount'], $depNo,
                    $item['narration'] ?? ($data['memo'] ?? ''), $user,
                    $item['dimension_id'] ?? null, $item['dimension2_id'] ?? null);
            }

            DB::table('gld_transactions')->insert($glLines);

            return ApiResponse::created(['id' => $deposit, 'dep_no' => $depNo, 'amount' => $total], 'Deposit posted');
        });
    }

    public function voidDeposit(int $id): JsonResponse
    {
        $dep = DB::table('bank_deposits')->find($id);
        if (! $dep) return ApiResponse::notFound('Deposit not found');
        if ($dep->status === 'voided') return ApiResponse::validationError(['status' => 'Already voided']);

        return DB::transaction(function () use ($dep) {
            DB::table('bank_deposits')->where('id', $dep->id)->update(['status' => 'voided', 'updated_at' => now()]);
            $this->reverseGl(self::TYPE_DEPOSIT, $dep->dep_no, now()->toDateString());
            return ApiResponse::success(null, 'Deposit voided');
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BANK TRANSFERS
    // ─────────────────────────────────────────────────────────────────────────
    public function transfersIndex(Request $request): JsonResponse
    {
        $q = DB::table('bank_transfers')->orderByDesc('transfer_date')->orderByDesc('id');
        if ($request->filled('from')) $q->where('transfer_date', '>=', $request->from);
        if ($request->filled('to'))   $q->where('transfer_date', '<=', $request->to);
        return ApiResponse::success($q->limit(500)->get());
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_account_code' => 'required|string',
            'to_account_code'   => 'required|string|different:from_account_code',
            'transfer_date'     => 'required|date',
            'reference'         => 'nullable|string|max:80',
            'amount'            => 'required|numeric|min:0.01',
            'bank_charge'       => 'nullable|numeric|min:0',
            'cheque_no'         => 'nullable|string|max:40',
            'memo'              => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $transferNo = $this->nextRef('funds_transfer');
            $user       = $request->user()?->user_id ?? 'system';
            $amount     = $data['amount'];
            $charge     = $data['bank_charge'] ?? 0;

            $transfer = DB::table('bank_transfers')->insertGetId([
                'transfer_no'       => $transferNo,
                'from_account_code' => $data['from_account_code'],
                'to_account_code'   => $data['to_account_code'],
                'transfer_date'     => $data['transfer_date'],
                'reference'         => $data['reference'] ?? null,
                'amount'            => $amount,
                'bank_charge'       => $charge,
                'cheque_no'         => $data['cheque_no'] ?? null,
                'memo'              => $data['memo'] ?? null,
                'status'            => 'posted',
                'created_by'        => $user,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $transNo = $this->nextGlTransNo();
            $glLines = [];
            $memo    = $data['memo'] ?? "Transfer {$transferNo}";

            // DR to_account, CR from_account
            $glLines[] = $this->glLine(self::TYPE_TRANSFER, $transNo, $data['transfer_date'],
                $data['to_account_code'], $amount, $transferNo, $memo, $user);
            $glLines[] = $this->glLine(self::TYPE_TRANSFER, $transNo, $data['transfer_date'],
                $data['from_account_code'], -($amount + $charge), $transferNo, $memo, $user);

            // Bank charge: DR expense (use a dedicated charge account or pass through)
            if ($charge > 0) {
                // Bank charges GL account — 501036 (Bank Charges) from our COA
                $glLines[] = $this->glLine(self::TYPE_TRANSFER, $transNo, $data['transfer_date'],
                    '501036', $charge, $transferNo, 'Bank charge – '.$transferNo, $user);
            }

            DB::table('gld_transactions')->insert($glLines);

            return ApiResponse::created(['id' => $transfer, 'transfer_no' => $transferNo], 'Transfer posted');
        });
    }

    public function voidTransfer(int $id): JsonResponse
    {
        $tr = DB::table('bank_transfers')->find($id);
        if (! $tr) return ApiResponse::notFound('Transfer not found');
        if ($tr->status === 'voided') return ApiResponse::validationError(['status' => 'Already voided']);

        return DB::transaction(function () use ($tr) {
            DB::table('bank_transfers')->where('id', $tr->id)->update(['status' => 'voided', 'updated_at' => now()]);
            $this->reverseGl(self::TYPE_TRANSFER, $tr->transfer_no, now()->toDateString());
            return ApiResponse::success(null, 'Transfer voided');
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bank account balance
    // ─────────────────────────────────────────────────────────────────────────
    public function accountBalance(Request $request): JsonResponse
    {
        $code    = $request->input('code');
        $asOf    = $request->input('as_of', now()->toDateString());

        $balance = DB::table('gld_transactions')
            ->where('account_code', $code)
            ->where('tran_date', '<=', $asOf)
            ->sum('amount');

        return ApiResponse::success(['code' => $code, 'balance' => (float) $balance]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────
    private function nextRef(string $type): string
    {
        $ref = DB::table('transaction_references')->where('trans_type', $type)->first();
        $prefix = $ref?->prefix ?? '';

        // Count existing records for this type to get next sequence
        $table = match ($type) {
            'bank_payment'  => 'bank_payments',
            'bank_deposit'  => 'bank_deposits',
            'funds_transfer'=> 'bank_transfers',
            default         => 'bank_payments',
        };

        $count = DB::table($table)->count() + 1;
        $seq   = str_pad($count, 3, '0', STR_PAD_LEFT);
        $year  = date('Y');

        return $prefix ? "{$prefix}/{$seq}/{$year}" : "{$seq}/{$year}";
    }

    private function nextGlTransNo(): int
    {
        return (int)(DB::table('gld_transactions')->max('trans_no') ?? 0) + 1;
    }

    private function glLine(int $type, int $transNo, string $date, string $accountCode,
        float $amount, string $reference, string $narration, string $user,
        ?int $dimId = null, ?int $dim2Id = null): array
    {
        return [
            'type'         => $type,
            'trans_no'     => $transNo,
            'tran_date'    => $date,
            'account_code' => $accountCode,
            'reference'    => $reference,
            'narration'    => $narration,
            'amount'       => $amount,
            'created_by'   => $user,
            'dimension_id' => $dimId,
            'dimension2_id'=> $dim2Id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
    }

    private function reverseGl(int $type, string $reference, string $voidDate): void
    {
        $orig = DB::table('gld_transactions')
            ->where('type', $type)->where('reference', $reference)->get();
        if ($orig->isEmpty()) return;

        $transNo = $this->nextGlTransNo();
        $inserts = $orig->map(fn ($l) => [
            'type'          => $type,
            'trans_no'      => $transNo,
            'tran_date'     => $voidDate,
            'account_code'  => $l->account_code,
            'reference'     => 'VOID-' . $reference,
            'narration'     => 'VOID: ' . $l->narration,
            'amount'        => -$l->amount,
            'created_by'    => 'system',
            'dimension_id'  => $l->dimension_id,
            'dimension2_id' => $l->dimension2_id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ])->toArray();

        DB::table('gld_transactions')->insert($inserts);
    }
}
