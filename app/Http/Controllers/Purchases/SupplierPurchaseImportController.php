<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PaymentVoucher;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplierPurchaseImportController extends Controller
{
    /**
     * Expected CSV columns (case-insensitive):
     * Member No, Name, Date(YYYY-MM-DD), Amount, Bank, Branch, Account No, Reference, Narration
     */
    private const COL_MAP = [
        'member no'        => 'member_no',
        'member_no'        => 'member_no',
        'memberno'         => 'member_no',
        'name'             => 'name',
        'date'             => 'date',
        'date(yyyy-mm-dd)' => 'date',
        'amount'           => 'amount',
        'bank'             => 'bank',
        'branch'           => 'branch',
        'account no'       => 'account_no',
        'account_no'       => 'account_no',
        'accountno'        => 'account_no',
        'reference'        => 'reference',
        'ref'              => 'reference',
        'narration'        => 'narration',
        'memo'             => 'narration',
        'description'      => 'narration',
    ];

    private function parseRows(Request $request): array
    {
        $file   = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        // Strip UTF-8 BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = null;
        $rows    = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = array_map(fn($h) => strtolower(trim($h)), $line);
                continue;
            }
            if (count(array_filter($line)) === 0) continue;
            $rows[] = array_combine($headers, array_pad($line, count($headers), ''));
        }
        fclose($handle);

        return [$headers, $rows];
    }

    private function mapRow(array $raw): array
    {
        $r = [];
        foreach ($raw as $header => $value) {
            $field = self::COL_MAP[$header] ?? null;
            if ($field) $r[$field] = trim($value);
        }

        // Normalise date
        if (!empty($r['date'])) {
            $d = date_create($r['date']);
            $r['date'] = $d ? $d->format('Y-m-d') : date('Y-m-d');
        } else {
            $r['date'] = date('Y-m-d');
        }

        $r['amount']     = isset($r['amount'])    ? (float) str_replace(',', '', $r['amount']) : 0;
        $r['member_no']  = $r['member_no']  ?? '';
        $r['name']       = $r['name']       ?? '';
        $r['bank']       = $r['bank']       ?? '';
        $r['branch']     = $r['branch']     ?? '';
        $r['account_no'] = $r['account_no'] ?? '';
        $r['reference']  = $r['reference']  ?? '';
        $r['narration']  = $r['narration']  ?? '';

        return $r;
    }

    /**
     * POST /purchases/suppliers/import-purchases/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240']);

        [$headers, $rows] = $this->parseRows($request);

        $preview = array_slice($rows, 0, 20);
        $mapped  = array_map(fn($r) => $this->mapRow($r), $preview);

        // Resolve supplier names for preview
        foreach ($mapped as &$row) {
            if ($row['member_no'] !== '') {
                $sup = Supplier::where('memberNumber', $row['member_no'])
                    ->select('supplierId', 'supplierName')
                    ->first();
                $row['_supplier_id']   = $sup?->supplierId;
                $row['_supplier_name'] = $sup?->supplierName ?? '⚠ Not found';
            } else {
                $row['_supplier_id']   = null;
                $row['_supplier_name'] = '—';
            }
        }
        unset($row);

        return ApiResponse::success([
            'headers'    => $headers,
            'preview'    => $preview,
            'mapped'     => $mapped,
            'total_rows' => count($rows),
        ], 'Preview ready');
    }

    /**
     * POST /purchases/suppliers/import-purchases/confirm
     * Creates a posted payment voucher for each row (upsert: skip if same member+date+amount already exists)
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240']);

        [, $rows] = $this->parseRows($request);

        $inserted = 0;
        $skipped  = 0;
        $errors   = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $raw) {
                $r = $this->mapRow($raw);

                if ($r['member_no'] === '' || $r['amount'] <= 0) {
                    $skipped++;
                    continue;
                }

                // Resolve supplier
                $supplier = Supplier::where('memberNumber', $r['member_no'])->first();
                if (!$supplier) {
                    $errors[] = 'Row ' . ($i + 2) . ': Member No "' . $r['member_no'] . '" not found';
                    if (count($errors) >= 10) break;
                    continue;
                }

                try {
                    PaymentVoucher::create([
                        'pvn_no'                 => PaymentVoucher::nextPvnNo(),
                        'supplier_id'            => $supplier->supplierId,
                        'bank_account_code'      => '',
                        'date_paid'              => $r['date'],
                        'reference'              => $r['reference'],
                        'bank_cheque'            => $r['bank'] . ($r['branch'] ? ' / ' . $r['branch'] : ''),
                        'type'                   => 'bank',
                        'withholding_tax_amount' => 0,
                        'amount'                 => $r['amount'],
                        'cheque_no'              => $r['account_no'],
                        'memo'                   => $r['narration'] ?: ('Bank payment import for ' . $r['member_no']),
                        'status'                 => 'posted',
                        'created_by'             => Auth::id(),
                    ]);
                    $inserted++;
                } catch (\Throwable $e) {
                    $errors[] = 'Row ' . ($i + 2) . ': ' . $e->getMessage();
                    if (count($errors) >= 10) break;
                }
            }

            if (!empty($errors)) {
                DB::rollBack();
                return ApiResponse::validationError(['errors' => $errors]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::validationError(['error' => $e->getMessage()]);
        }

        return ApiResponse::success([
            'inserted' => $inserted,
            'skipped'  => $skipped,
        ], "Import complete: {$inserted} payments created, {$skipped} skipped.");
    }
}
