<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportServicesController extends Controller
{
    // ── Form data: active checkoff services + current month/year ─────────────
    public function formData(): JsonResponse
    {
        $services = DB::table('checkoff_services')
            ->where('active', true)
            ->orderBy('service_name')
            ->get(['id', 'service_name', 'service_type']);

        return ApiResponse::success(compact('services'), 'Form data');
    }

    // ── Import: parse file and upsert farmer_checkoff_entries ─────────────────
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'service_id' => 'required|integer|exists:checkoff_services,id',
            'month'      => 'required|integer|between:1,12',
            'year'       => 'required|integer|min:2000|max:2100',
            'file'       => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
        ]);

        $serviceId = (int) $request->service_id;
        $month     = (int) $request->month;
        $year      = (int) $request->year;
        $file      = $request->file('file');
        $ext       = strtolower($file->getClientOriginalExtension());

        // ── Parse file into rows ──────────────────────────────────────────────
        $rows = $ext === 'xlsx' || $ext === 'xls'
            ? $this->parseXlsx($file->getRealPath())
            : $this->parseCsv($file->getRealPath());

        if (empty($rows)) {
            return ApiResponse::validationError(['file' => ['File appears to be empty or unreadable.']]);
        }

        // ── Detect header row → find farmer_no/member_no and amount columns ──
        $header   = array_map(fn($h) => strtolower(trim((string) $h)), $rows[0]);
        $dataRows = array_slice($rows, 1);

        $farmerNoCol = $this->findCol($header, ['farmer_no', 'farmer no', 'farmerno', 'farmer_number']);
        $memberNoCol = $this->findCol($header, ['member_no', 'member no', 'memberno', 'member_number']);
        $amountCol   = $this->findCol($header, ['amount', 'deduction', 'value', 'amt']);

        if ($farmerNoCol === null && $memberNoCol === null) {
            return ApiResponse::validationError(['file' => ['Column "farmer_no" or "member_no" not found in header row.']]);
        }
        if ($amountCol === null) {
            return ApiResponse::validationError(['file' => ['Column "amount" not found in header row.']]);
        }

        // ── Build lookup: farmer_no/member_no → farmer.id ────────────────────
        $farmerLookup = [];
        if ($farmerNoCol !== null) {
            DB::table('farmers')->select('id', 'farmer_no')->chunk(2000, function ($chunk) use (&$farmerLookup) {
                foreach ($chunk as $f) $farmerLookup['fno:' . strtoupper(trim($f->farmer_no))] = $f->id;
            });
        }
        if ($memberNoCol !== null) {
            DB::table('farmers')->whereNotNull('member_no')->select('id', 'member_no')->chunk(2000, function ($chunk) use (&$farmerLookup) {
                foreach ($chunk as $f) $farmerLookup['mno:' . strtoupper(trim($f->member_no))] = $f->id;
            });
        }

        // ── Process rows ──────────────────────────────────────────────────────
        $upserts    = [];
        $skipped    = [];
        $now        = now();

        foreach ($dataRows as $lineNo => $row) {
            $farmerNo = $farmerNoCol !== null ? strtoupper(trim((string) ($row[$farmerNoCol] ?? ''))) : null;
            $memberNo = $memberNoCol !== null ? strtoupper(trim((string) ($row[$memberNoCol] ?? ''))) : null;
            $amount   = (float) str_replace([',', ' '], '', (string) ($row[$amountCol] ?? 0));

            if ($amount <= 0) continue;   // skip zero/blank rows

            $farmerId = null;
            if ($farmerNo) $farmerId = $farmerLookup['fno:' . $farmerNo] ?? null;
            if (!$farmerId && $memberNo) $farmerId = $farmerLookup['mno:' . $memberNo] ?? null;

            if (!$farmerId) {
                $skipped[] = 'Row ' . ($lineNo + 2) . ': "' . ($farmerNo ?: $memberNo) . '" not found';
                continue;
            }

            $upserts[] = [
                'farmer_id'    => $farmerId,
                'month'        => $month,
                'year'         => $year,
                'service_id'   => $serviceId,
                'service_name' => null,
                'amount'       => $amount,
                'note'         => 'Imported',
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (empty($upserts)) {
            return ApiResponse::validationError(['file' => ['No valid rows to import. Check farmer numbers and amounts.']]);
        }

        DB::table('farmer_checkoff_entries')->upsert(
            $upserts,
            ['farmer_id', 'month', 'year', 'service_id'],
            ['amount', 'note', 'updated_at']
        );

        return ApiResponse::success([
            'imported' => count($upserts),
            'skipped'  => count($skipped),
            'warnings' => array_slice($skipped, 0, 20),  // first 20 warnings only
        ], count($upserts) . ' records imported successfully');
    }

    // ── Parse CSV ─────────────────────────────────────────────────────────────
    private function parseCsv(string $path): array
    {
        $rows = [];
        if (($fh = fopen($path, 'r')) === false) return [];
        while (($row = fgetcsv($fh)) !== false) {
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    // ── Parse XLSX (Office Open XML) without external libs ───────────────────
    private function parseXlsx(string $path): array
    {
        $rows = [];
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) return [];

            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $sst = $zip->getFromName('xl/sharedStrings.xml');
            $zip->close();

            if (!$xml) return [];

            // Build shared strings table
            $strings = [];
            if ($sst) {
                $sstXml = simplexml_load_string($sst);
                foreach ($sstXml->si as $si) {
                    $strings[] = (string) ($si->t ?? implode('', array_map(fn($r) => (string) $r->t, iterator_to_array($si->r ?? []))));
                }
            }

            $sheetXml = simplexml_load_string($xml);
            $sheetXml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheetRows = $sheetXml->xpath('//x:sheetData/x:row');

            foreach ($sheetRows as $row) {
                $cells     = [];
                $maxColIdx = 0;

                foreach ($row->c as $cell) {
                    $colIdx = $this->xlColIndex((string) $cell['r']);
                    if ($colIdx > $maxColIdx) $maxColIdx = $colIdx;
                }

                $cells = array_fill(0, $maxColIdx + 1, '');

                foreach ($row->c as $cell) {
                    $colIdx = $this->xlColIndex((string) $cell['r']);
                    $type   = (string) ($cell['t'] ?? '');
                    $val    = (string) ($cell->v ?? '');

                    if ($type === 's') {
                        $cells[$colIdx] = $strings[(int) $val] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $cells[$colIdx] = (string) ($cell->is->t ?? '');
                    } else {
                        $cells[$colIdx] = $val;
                    }
                }

                $rows[] = $cells;
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $rows;
    }

    // ── Convert Excel column reference (e.g. "B3") → 0-based index ──────────
    private function xlColIndex(string $cellRef): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($cellRef), $m);
        $col = $m[1] ?? 'A';
        $idx = 0;
        foreach (str_split($col) as $c) {
            $idx = $idx * 26 + (ord($c) - ord('A') + 1);
        }
        return $idx - 1;
    }

    // ── Find column index by name aliases ─────────────────────────────────────
    private function findCol(array $header, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $pos = array_search($alias, $header, true);
            if ($pos !== false) return (int) $pos;
        }
        return null;
    }
}
