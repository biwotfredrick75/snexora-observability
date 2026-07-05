<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerDeposit;
use App\Models\CustomerPayment;
use App\Models\GldTransaction;
use App\Models\MpesaTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ImportController extends Controller
{
    // Column definitions per import type
    private const COLUMNS = [
        'journal' => ['date', 'gl_code', 'narration', 'reference', 'debit', 'credit'],
        'deposit' => ['date', 'debtor_no', 'amount', 'bank_account_code', 'reference', 'memo'],
        'payment' => ['date', 'debtor_no', 'amount', 'payment_type', 'bank_account_code', 'reference', 'memo'],
        'mpesa'   => ['TransID', 'TransactionType', 'TransTime', 'TransAmount', 'BusinessShortCode', 'BilRef', 'MSISDN', 'First_Name', 'Middle_Name', 'Last_Name'],
        'invoice' => ['date', 'debtor_no', 'stock_id', 'description', 'quantity', 'unit_price', 'reference'],
    ];

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'        => 'required|file|mimes:csv,txt|max:5120',
            'import_type' => 'required|in:journal,deposit,payment,mpesa,invoice',
            'separator'   => 'nullable|string|max:2',
            'trial_run'   => 'nullable|in:0,1',
        ]);

        $importType = $request->import_type;
        $separator  = $request->separator ?: ',';
        $trialRun   = $request->trial_run !== '0';
        $user       = auth()->user()?->user_id ?? 'system';

        $path  = $request->file('file')->getRealPath();
        $lines = array_filter(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        if (empty($lines)) {
            return ApiResponse::error('File is empty', 422);
        }

        // Parse header
        $header = str_getcsv(array_shift($lines), $separator);
        $header = array_map('trim', $header);

        // Validate required columns exist
        $expected = self::COLUMNS[$importType];
        $missing  = array_diff($expected, $header);
        if ($missing) {
            return ApiResponse::error(
                'Missing columns: ' . implode(', ', $missing),
                422
            );
        }

        $rows    = [];
        $errors  = [];
        $preview = [];
        $rowNo   = 1;

        foreach ($lines as $line) {
            $rowNo++;
            $values = str_getcsv($line, $separator);
            $values = array_map('trim', $values);

            // Map to associative array using header
            $data = [];
            foreach ($header as $i => $col) {
                $data[$col] = $values[$i] ?? '';
            }

            // Validate row
            $rowErrors = $this->validateRow($importType, $data, $rowNo);
            if ($rowErrors) {
                $errors = array_merge($errors, $rowErrors);
            } else {
                $rows[] = $data;
            }

            if (count($preview) < 10) {
                $preview[] = $data;
            }
        }

        // ── Invoice: batch-level stock availability check ─────────────────────
        if ($importType === 'invoice' && empty($errors) && ! empty($rows)) {
            // Tally required qty per stock_id across all valid rows
            $required = [];
            foreach ($rows as $row) {
                $sid = $row['stock_id'];
                $qty = (float) ($row['quantity'] ?? 0);
                $required[$sid] = ($required[$sid] ?? 0) + $qty;
            }

            // Fetch available qty (sum of movements) for each distinct item
            $available = DB::table('stock_movements')
                ->whereIn('stock_id', array_keys($required))
                ->selectRaw('TRIM(stock_id) as stock_id, SUM(qty) as available_qty')
                ->groupBy('stock_id')
                ->pluck('available_qty', 'stock_id');

            foreach ($required as $sid => $needed) {
                $avail = (float) ($available[$sid] ?? 0);
                if ($needed > $avail) {
                    $errors[] = [
                        'row'     => 'Batch',
                        'field'   => "stock_id:{$sid}",
                        'message' => "Insufficient stock for {$sid}: need {$needed}, available " . round($avail, 2),
                    ];
                }
            }
        }

        $imported = 0;

        // Import if not trial run and no errors
        if (! $trialRun && empty($errors) && ! empty($rows)) {
            try {
                DB::transaction(function () use ($importType, $rows, $user, &$imported, $request) {
                    foreach ($rows as $row) {
                        $this->importRow($importType, $row, $user, $request);
                        $imported++;
                    }
                });
            } catch (\Throwable $e) {
                return ApiResponse::error('Import failed: ' . $e->getMessage(), 500);
            }
        } elseif ($trialRun) {
            $imported = count($rows); // valid rows count
        }

        return ApiResponse::success([
            'rows'     => count($lines),
            'imported' => $imported,
            'errors'   => $errors,
            'preview'  => $preview,
        ], $trialRun ? 'Trial run complete' : "{$imported} record(s) imported");
    }

    // ── Row validation ────────────────────────────────────────────────────────

    private function validateRow(string $type, array $data, int $row): array
    {
        $errors = [];

        $rules = match ($type) {
            'journal' => [
                'date'    => 'required|date',
                'gl_code' => 'required|string|exists:gl_accounts,code',
                'debit'   => 'nullable|numeric|min:0',
                'credit'  => 'nullable|numeric|min:0',
            ],
            'deposit' => [
                'date'     => 'required|date',
                'debtor_no'=> 'required|string|exists:customers,debtor_no',
                'amount'   => 'required|numeric|min:0.01',
            ],
            'payment' => [
                'date'        => 'required|date',
                'debtor_no'   => 'required|string|exists:customers,debtor_no',
                'amount'      => 'required|numeric|min:0.01',
                'payment_type'=> 'required|string',
            ],
            'mpesa' => [
                'TransID'     => 'required|string',
                'TransAmount' => 'required|numeric|min:0',
                'TransTime'   => 'required|string',
            ],
            'invoice' => [
                'date'       => 'required|date',
                'debtor_no'  => 'required|string|exists:customers,debtor_no',
                'stock_id'   => 'required|string|exists:items,stock_id',
                'quantity'   => 'required|numeric|min:0.001',
                'unit_price' => 'required|numeric|min:0',
            ],
            default => [],
        };

        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[] = ['row' => $row, 'field' => $field, 'message' => $messages[0]];
            }
        }

        // Extra: journal must have debit or credit but not both
        if ($type === 'journal') {
            $d = (float) ($data['debit'] ?? 0);
            $c = (float) ($data['credit'] ?? 0);
            if ($d == 0 && $c == 0) {
                $errors[] = ['row' => $row, 'field' => 'debit/credit', 'message' => 'Debit or credit must be non-zero'];
            }
        }

        // Duplicate TransID check for mpesa
        if ($type === 'mpesa' && ! empty($data['TransID'])) {
            if (DB::table('mpesa_payments')->where('TransID', $data['TransID'])->exists()) {
                $errors[] = ['row' => $row, 'field' => 'TransID', 'message' => "TransID {$data['TransID']} already exists"];
            }
        }

        return $errors;
    }

    // ── Row import ─────────────────────────────────────────────────────────────

    private function importRow(string $type, array $data, string $user, Request $request): void
    {
        match ($type) {
            'deposit' => $this->importDeposit($data, $user, $request),
            'payment' => $this->importPayment($data, $user, $request),
            'mpesa'   => $this->importMpesa($data),
            'journal' => $this->importJournal($data, $user),
            default   => null,
        };
    }

    private function importDeposit(array $d, string $user, Request $request): void
    {
        $amount  = (float) $d['amount'];
        $deposit = CustomerDeposit::create([
            'deposit_no'         => 'TEMP-' . uniqid(),
            'debtor_no'          => $d['debtor_no'],
            'deposit_date'       => $d['date'],
            'bank_account_code'  => $d['bank_account_code'] ?: ($request->receivable_acc ?: null),
            'amount'             => $amount,
            'deposit_amount'     => $amount,
            'allocated_amount'   => 0,
            'unallocated_amount' => $amount,
            'reference'          => $d['reference'] ?? null,
            'memo'               => $d['memo'] ?? null,
            'status'             => 'posted',
            'created_by'         => $user,
        ]);
        $deposit->update(['deposit_no' => CustomerDeposit::nextDepositNo()]);
    }

    private function importPayment(array $d, string $user, Request $request): void
    {
        $amount  = (float) $d['amount'];
        $payment = CustomerPayment::create([
            'payment_no'         => 'TEMP-' . uniqid(),
            'debtor_no'          => $d['debtor_no'],
            'payment_date'       => $d['date'],
            'bank_account_code'  => $d['bank_account_code'] ?: ($request->receivable_acc ?: null),
            'payment_type'       => $d['payment_type'] ?: 'cash',
            'amount'             => $amount,
            'discount_amount'    => 0,
            'bank_charge'        => 0,
            'allocated_amount'   => 0,
            'unallocated_amount' => $amount,
            'reference'          => $d['reference'] ?? null,
            'memo'               => $d['memo'] ?? null,
            'status'             => 'posted',
            'created_by'         => $user,
        ]);
        $payment->update(['payment_no' => CustomerPayment::nextPaymentNo()]);
    }

    private function importMpesa(array $d): void
    {
        // Parse TransTime (YYYYMMDDHHmmss) → datetime
        $t = $d['TransTime'];
        $transDate = strlen($t) >= 14
            ? date('Y-m-d H:i:s', mktime(
                (int) substr($t, 8, 2), (int) substr($t, 10, 2), (int) substr($t, 12, 2),
                (int) substr($t, 4, 2), (int) substr($t, 6, 2), (int) substr($t, 0, 4)
            ))
            : now()->toDateTimeString();

        DB::table('mpesa_payments')->insert([
            'TransactionType'   => $d['TransactionType'] ?: 'Customer Merchant Payment',
            'TransID'           => $d['TransID'],
            'TransTime'         => $d['TransTime'],
            'TransAmount'       => (float) $d['TransAmount'],
            'BusinessShortCode' => $d['BusinessShortCode'] ?? null,
            'BilRef'            => $d['BilRef'] ?? null,
            'MSISDN'            => $d['MSISDN'] ?? null,
            'First_Name'        => $d['First_Name'] ?? null,
            'Middle_Name'       => $d['Middle_Name'] ?? null,
            'Last_Name'         => $d['Last_Name'] ?? null,
            'OrgAccountBalance' => 0,
            'custBalance'       => 0,
            'allocated'         => 0,
            'trans_date'        => $transDate,
            'transfer_user'     => '',
            'transfer_time'     => null,
            'debtor_no'         => null,
            'payment_id'        => null,
        ]);
    }

    private function importJournal(array $d, string $user): void
    {
        $debit  = (float) ($d['debit']  ?? 0);
        $credit = (float) ($d['credit'] ?? 0);
        $amount = $debit > 0 ? $debit : -$credit;

        GldTransaction::create([
            'trans_no'     => DB::table('gld_transactions')->max('trans_no') + 1,
            'type'         => 1,
            'tran_date'    => $d['date'],
            'account_code' => $d['gl_code'],
            'reference'    => $d['reference'] ?? null,
            'narration'    => $d['narration'] ?? null,
            'amount'       => $amount,
            'created_by'   => $user,
        ]);
    }
}
