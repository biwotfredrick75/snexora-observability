<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BankStatementImportController extends Controller
{
    // ── Shared form data ──────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        $bankAccounts = DB::table('gl_accounts as a')
            ->join('gl_account_groups as g', 'a.group_id', '=', 'g.id')
            ->where('g.code', '1021')
            ->where('a.inactive', false)
            ->select('a.code', 'a.name')
            ->orderBy('a.code')
            ->get();

        return ApiResponse::success(['bankAccounts' => $bankAccounts]);
    }

    // ── Parse uploaded CSV — preview only, does NOT save ─────────────────────
    public function parse(Request $request): JsonResponse
    {
        $request->validate([
            'account_code' => 'required|string',
            'file'         => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $path = $request->file('file')->getRealPath();
        $rows = $this->parseCsv($path);

        if (empty($rows)) {
            return ApiResponse::error('No data rows found in the uploaded file.', 422);
        }

        $accountCode = $request->account_code;

        // Auto-match each line against gld_transactions (same account, ±3 days, exact amount)
        foreach ($rows as &$row) {
            $row['matched_gld'] = null;

            // Net amount from bank's perspective: credit = money in (GL debit on bank), debit = money out (GL credit)
            $glAmount = $row['credit'] > 0 ? $row['credit'] : -$row['debit'];

            if (abs($glAmount) < 0.005) continue;

            $match = DB::table('gld_transactions')
                ->where('account_code', $accountCode)
                ->whereBetween('tran_date', [
                    Carbon::parse($row['date'])->subDays(3)->toDateString(),
                    Carbon::parse($row['date'])->addDays(3)->toDateString(),
                ])
                ->where('amount', $glAmount)
                ->whereNotIn('id', function ($q) {
                    $q->select('matched_gld_id')
                      ->from('bank_statement_lines')
                      ->whereNotNull('matched_gld_id');
                })
                ->select('id', 'trans_no', 'tran_date', 'reference', 'narration', 'amount')
                ->first();

            if ($match) {
                $row['matched_gld'] = $match;
                $row['match_type']  = 'auto';
            }
        }
        unset($row);

        $stats = [
            'total'    => count($rows),
            'matched'  => count(array_filter($rows, fn ($r) => $r['matched_gld'] !== null)),
            'from'     => $rows[0]['date'] ?? null,
            'to'       => end($rows)['date'] ?? null,
        ];

        return ApiResponse::success(['rows' => $rows, 'stats' => $stats]);
    }

    // ── Save the import after user confirms ───────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_code'    => 'required|string',
            'filename'        => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
            'closing_balance' => 'nullable|numeric',
            'rows'            => 'required|array|min:1',
            'rows.*.date'          => 'required|date',
            'rows.*.description'   => 'nullable|string',
            'rows.*.reference'     => 'nullable|string',
            'rows.*.debit'         => 'nullable|numeric',
            'rows.*.credit'        => 'nullable|numeric',
            'rows.*.balance'       => 'nullable|numeric',
            'rows.*.matched_gld_id'=> 'nullable|integer',
            'rows.*.match_type'    => 'nullable|string',
        ]);

        $rows = $data['rows'];
        $dates = array_column($rows, 'date');

        DB::beginTransaction();
        try {
            $importId = DB::table('bank_statement_imports')->insertGetId([
                'account_code'    => $data['account_code'],
                'statement_from'  => min($dates),
                'statement_to'    => max($dates),
                'opening_balance' => $data['opening_balance'] ?? 0,
                'closing_balance' => $data['closing_balance'] ?? 0,
                'filename'        => $data['filename'] ?? null,
                'status'          => 'imported',
                'imported_by'     => $request->user()?->user_id ?? 'system',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            $lines = array_map(fn ($r) => [
                'import_id'      => $importId,
                'line_date'      => $r['date'],
                'description'    => $r['description'] ?? null,
                'reference'      => $r['reference'] ?? null,
                'debit'          => (float)($r['debit']  ?? 0),
                'credit'         => (float)($r['credit'] ?? 0),
                'balance'        => isset($r['balance']) ? (float)$r['balance'] : null,
                'matched_gld_id' => $r['matched_gld_id'] ?? null,
                'match_type'     => $r['match_type'] ?? null,
            ], $rows);

            DB::table('bank_statement_lines')->insert($lines);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::serverError('Failed to save import: ' . $e->getMessage());
        }

        $import = DB::table('bank_statement_imports')->find($importId);
        return ApiResponse::success($import, 'Statement imported successfully');
    }

    // ── List past imports ─────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('bank_statement_imports')->orderByDesc('created_at');
        if ($request->filled('account_code')) {
            $q->where('account_code', $request->account_code);
        }
        $imports = $q->limit(100)->get()->map(function ($imp) {
            $imp->total    = DB::table('bank_statement_lines')->where('import_id', $imp->id)->count();
            $imp->matched  = DB::table('bank_statement_lines')->where('import_id', $imp->id)->whereNotNull('matched_gld_id')->count();
            return $imp;
        });
        return ApiResponse::success($imports);
    }

    // ── Lines for one import ──────────────────────────────────────────────────
    public function lines(int $id): JsonResponse
    {
        $import = DB::table('bank_statement_imports')->find($id);
        if (! $import) return ApiResponse::notFound('Import not found');

        $lines = DB::table('bank_statement_lines as l')
            ->leftJoin('gld_transactions as g', 'l.matched_gld_id', '=', 'g.id')
            ->where('l.import_id', $id)
            ->select('l.*', 'g.trans_no', 'g.tran_date as gl_date', 'g.reference as gl_ref', 'g.narration as gl_narration')
            ->orderBy('l.line_date')
            ->get();

        return ApiResponse::success(['import' => $import, 'lines' => $lines]);
    }

    // ── Manually match a statement line to a GL transaction ───────────────────
    public function match(Request $request): JsonResponse
    {
        $data = $request->validate([
            'line_id'        => 'required|integer',
            'gld_transaction_id' => 'required|integer',
        ]);

        DB::table('bank_statement_lines')
            ->where('id', $data['line_id'])
            ->update([
                'matched_gld_id' => $data['gld_transaction_id'],
                'match_type'     => 'manual',
            ]);

        return ApiResponse::success(null, 'Matched');
    }

    // ── Remove a match ────────────────────────────────────────────────────────
    public function unmatch(Request $request): JsonResponse
    {
        $request->validate(['line_id' => 'required|integer']);

        DB::table('bank_statement_lines')
            ->where('id', $request->line_id)
            ->update(['matched_gld_id' => null, 'match_type' => null]);

        return ApiResponse::success(null, 'Unmatched');
    }

    // ── GL transactions available to match (unmatched, same account, near date) ─
    public function glCandidates(Request $request): JsonResponse
    {
        $request->validate([
            'account_code' => 'required|string',
            'date'         => 'required|date',
            'amount'       => 'required|numeric',
        ]);

        $date   = $request->date;
        $amount = (float)$request->amount;

        // For the bank statement: credit = GL debit (positive), debit = GL credit (negative)
        $glAmount = $amount > 0 ? $amount : -abs($amount);

        $candidates = DB::table('gld_transactions')
            ->where('account_code', $request->account_code)
            ->whereBetween('tran_date', [
                Carbon::parse($date)->subDays(7)->toDateString(),
                Carbon::parse($date)->addDays(7)->toDateString(),
            ])
            ->whereRaw('ABS(amount - ?) < 0.01', [abs($glAmount)])
            ->whereNotIn('id', function ($q) {
                $q->select('matched_gld_id')
                  ->from('bank_statement_lines')
                  ->whereNotNull('matched_gld_id');
            })
            ->select('id', 'trans_no', 'tran_date', 'reference', 'narration', 'amount')
            ->limit(20)
            ->get();

        return ApiResponse::success($candidates);
    }

    // ── CSV Parser ────────────────────────────────────────────────────────────
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) return [];

        // Read first non-empty line as header
        $headers = null;
        while (($raw = fgetcsv($handle)) !== false) {
            $raw = array_map('trim', $raw);
            if (! empty(array_filter($raw))) { $headers = $raw; break; }
        }
        if (! $headers) { fclose($handle); return []; }

        // Normalise headers to lowercase
        $hmap = array_map('strtolower', $headers);

        // Detect column indices
        $col = [
            'date'   => $this->findCol($hmap, ['date', 'tran date', 'transaction date', 'value date', 'txn date']),
            'desc'   => $this->findCol($hmap, ['description', 'narration', 'particulars', 'details', 'remarks', 'memo']),
            'ref'    => $this->findCol($hmap, ['reference', 'ref', 'cheque no', 'chq no', 'transaction id', 'txn id']),
            'debit'  => $this->findCol($hmap, ['debit', 'debit amount', 'withdrawal', 'dr', 'dr amount', 'amount dr']),
            'credit' => $this->findCol($hmap, ['credit', 'credit amount', 'deposit', 'cr', 'cr amount', 'amount cr']),
            'amount' => $this->findCol($hmap, ['amount', 'net amount', 'transaction amount']),
            'balance'=> $this->findCol($hmap, ['balance', 'running balance', 'closing balance', 'available balance']),
        ];

        $rows = [];
        while (($raw = fgetcsv($handle)) !== false) {
            $raw = array_map('trim', $raw);
            if (empty(array_filter($raw))) continue;

            $date = $this->parseDate($col['date'] !== null ? ($raw[$col['date']] ?? '') : '');
            if (! $date) continue;

            $debit  = 0.0;
            $credit = 0.0;

            if ($col['debit'] !== null || $col['credit'] !== null) {
                $debit  = $this->parseMoney($col['debit']  !== null ? ($raw[$col['debit']]  ?? '') : '');
                $credit = $this->parseMoney($col['credit'] !== null ? ($raw[$col['credit']] ?? '') : '');
            } elseif ($col['amount'] !== null) {
                $amt = $this->parseMoney($raw[$col['amount']] ?? '');
                if ($amt < 0) $debit  = abs($amt);
                else          $credit = $amt;
            }

            if ($debit == 0 && $credit == 0) continue;

            $rows[] = [
                'date'        => $date,
                'description' => $col['desc']    !== null ? ($raw[$col['desc']]    ?? '') : '',
                'reference'   => $col['ref']     !== null ? ($raw[$col['ref']]     ?? '') : '',
                'debit'       => $debit,
                'credit'      => $credit,
                'balance'     => $col['balance'] !== null ? $this->parseMoney($raw[$col['balance']] ?? '') : null,
            ];
        }

        fclose($handle);
        return $rows;
    }

    private function findCol(array $hmap, array $candidates): ?int
    {
        foreach ($candidates as $c) {
            $idx = array_search($c, $hmap);
            if ($idx !== false) return (int)$idx;
        }
        // Partial match
        foreach ($candidates as $c) {
            foreach ($hmap as $i => $h) {
                if (str_contains($h, $c) || str_contains($c, $h)) return $i;
            }
        }
        return null;
    }

    private function parseDate(string $raw): ?string
    {
        if (empty(trim($raw))) return null;
        $raw = trim($raw);
        // Try various formats
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'Y/m/d', 'd.m.Y', 'd M Y', 'M d, Y', 'd-M-Y'];
        foreach ($formats as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $raw)?->toDateString();
            } catch (\Exception) {}
        }
        try { return Carbon::parse($raw)->toDateString(); } catch (\Exception) {}
        return null;
    }

    private function parseMoney(string $raw): float
    {
        // Remove currency symbols, spaces, parentheses (negatives sometimes shown as (1,234))
        $neg = str_starts_with($raw, '(') && str_ends_with($raw, ')');
        $clean = preg_replace('/[^0-9.\-]/', '', $raw);
        $val = (float)$clean;
        return $neg ? -abs($val) : $val;
    }
}
