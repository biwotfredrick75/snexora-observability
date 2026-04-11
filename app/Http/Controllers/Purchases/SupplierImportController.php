<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierImportController extends Controller
{
    /**
     * Expected CSV column order (case-insensitive header match):
     * Member No, Name, Route, Location, Gender, ID No, DOB(YYYY-MM-DD),
     * Bank, Branch, Account No, Phone No, NOK, NOK ID No, NOK Rel, NOK Tel, Status
     */
    private const COL_MAP = [
        'member no'        => 'memberNumber',
        'member_no'        => 'memberNumber',
        'memberno'         => 'memberNumber',
        'name'             => 'supplierName',
        'route'            => 'route',
        'location'         => 'address',
        'gender'           => 'gender',
        'id no'            => 'idNumber',
        'id_no'            => 'idNumber',
        'idno'             => 'idNumber',
        'dob'              => 'dateOfBirth',
        'dob(yyyy-mm-dd)'  => 'dateOfBirth',
        'bank'             => 'bankNumber',
        'branch'           => 'bankBranch',
        'account no'       => 'supplierAccountNumber',
        'account_no'       => 'supplierAccountNumber',
        'accountno'        => 'supplierAccountNumber',
        'phone no'         => 'contactPerson',
        'phone_no'         => 'contactPerson',
        'phoneno'          => 'contactPerson',
        'phone'            => 'contactPerson',
        'nok'              => 'nextOfKin',
        'nok id no'        => 'nextOfKinId',
        'nok_id_no'        => 'nextOfKinId',
        'nok rel'          => 'nextOfKinRelationship',
        'nok_rel'          => 'nextOfKinRelationship',
        'nok tel'          => 'nextOfKinTelephone',
        'nok_tel'          => 'nextOfKinTelephone',
        'status'           => '_status',
    ];

    private function parseRows(Request $request): array
    {
        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');

        // Detect BOM
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
            if (count(array_filter($line)) === 0) continue; // skip blank lines
            $rows[] = array_combine($headers, array_pad($line, count($headers), ''));
        }
        fclose($handle);

        return [$headers, $rows];
    }

    private function mapRow(array $raw): array
    {
        $mapped = [];
        foreach ($raw as $header => $value) {
            $field = self::COL_MAP[$header] ?? null;
            if ($field) {
                $mapped[$field] = trim($value);
            }
        }

        // Derive inactive from _status (Status=1 → active → inactive=false)
        $status = $mapped['_status'] ?? '1';
        $mapped['inactive'] = ($status === '0') ? 1 : 0;
        unset($mapped['_status']);

        // Also use address for supplierAddress
        if (!empty($mapped['address'])) {
            $mapped['supplierAddress'] = $mapped['address'];
        }

        // Defaults for required DB fields not in CSV
        $mapped['routeGroupId']            = $mapped['routeGroupId']            ?? 0;
        $mapped['gstNumber']               = $mapped['gstNumber']               ?? '';
        $mapped['supplierAccountNumber']   = $mapped['supplierAccountNumber']   ?? '';
        $mapped['bankAccount']             = $mapped['supplierAccountNumber'];
        $mapped['website']                 = '';
        $mapped['currencyCode']            = 'KES';
        $mapped['dimensionId']             = 0;
        $mapped['dimension2Id']            = 0;
        $mapped['creditLimit']             = 0;
        $mapped['purchaseAccount']         = '';
        $mapped['payableAccount']          = '';
        $mapped['paymentDiscountAccount']  = '';
        $mapped['notes']                   = '';
        $mapped['supplierType']            = 'farmer';
        $mapped['deductNhif']              = 0;
        $mapped['packingSharesLimit']      = 0;
        $mapped['savingsPerLitre']         = 0;
        $mapped['source']                  = 0;
        $mapped['registrationFeeComplete'] = 0;
        $mapped['sharesLimit']             = 0;
        $mapped['balanceMessageDate']      = date('Y-m-d');
        $mapped['isVendor']                = 0;

        // Normalise dateOfBirth
        if (!empty($mapped['dateOfBirth'])) {
            $d = date_create($mapped['dateOfBirth']);
            $mapped['dateOfBirth'] = $d ? $d->format('Y-m-d') : date('Y-m-d');
        } else {
            $mapped['dateOfBirth'] = date('Y-m-d');
        }

        // Fill optional string fields with empty string if missing
        foreach (['nextOfKin','nextOfKinId','nextOfKinRelationship','nextOfKinTelephone',
                  'bankNumber','bankBranch','idNumber','gender','route','address','supplierAddress',
                  'contactPerson','memberNumber','supplierName','supplierReference'] as $f) {
            if (!isset($mapped[$f])) $mapped[$f] = '';
        }
        $mapped['supplierReference'] = $mapped['memberNumber'];

        return $mapped;
    }

    /**
     * POST /purchases/suppliers/import/preview
     * Returns first 20 rows for preview (no DB write)
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240']);

        [$headers, $rows] = $this->parseRows($request);

        $preview = array_slice($rows, 0, 20);
        $mapped  = array_map(fn($r) => $this->mapRow($r), $preview);

        return ApiResponse::success([
            'headers'     => $headers,
            'preview'     => $preview,
            'mapped'      => $mapped,
            'total_rows'  => count($rows),
        ], 'Preview ready');
    }

    /**
     * POST /purchases/suppliers/import/confirm
     * Upserts all rows (match on memberNumber)
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240']);

        [, $rows] = $this->parseRows($request);

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;
        $errors   = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $raw) {
                $data = $this->mapRow($raw);
                $memberNo = $data['memberNumber'] ?? '';

                if ($memberNo === '') {
                    $skipped++;
                    continue;
                }

                try {
                    $exists = Supplier::where('memberNumber', $memberNo)->first();
                    if ($exists) {
                        $exists->update($data);
                        $updated++;
                    } else {
                        Supplier::create($data);
                        $inserted++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Row ' . ($i + 2) . ': ' . $e->getMessage();
                    if (count($errors) >= 10) break; // stop after 10 errors
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
            'updated'  => $updated,
            'skipped'  => $skipped,
        ], "Import complete: {$inserted} inserted, {$updated} updated, {$skipped} skipped.");
    }
}
