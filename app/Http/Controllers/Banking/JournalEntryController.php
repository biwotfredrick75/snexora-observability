<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\GlPostingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalEntryController extends Controller
{
    private const GL_TYPE = 0; // manual journal in gld_transactions

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('journal_entries')->orderByDesc('journal_date')->orderByDesc('id');
        if ($request->filled('from'))   $q->where('journal_date', '>=', $request->from);
        if ($request->filled('to'))     $q->where('journal_date', '<=', $request->to);
        if ($request->filled('status')) $q->where('status', $request->status);
        return ApiResponse::success($q->limit(500)->get());
    }

    public function show(int $id): JsonResponse
    {
        $journal = DB::table('journal_entries')->find($id);
        if (! $journal) return ApiResponse::notFound('Journal not found');
        $lines = DB::table('journal_entry_lines')
            ->join('gl_accounts', 'journal_entry_lines.account_code', '=', 'gl_accounts.code')
            ->where('journal_id', $id)
            ->get(['journal_entry_lines.*', 'gl_accounts.name as account_name']);
        return ApiResponse::success(['journal' => $journal, 'lines' => $lines]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'journal_date'        => 'required|date',
            'document_date'       => 'nullable|date',
            'event_date'          => 'nullable|date',
            'currency'            => 'nullable|string|max:10',
            'exchange_rate'       => 'nullable|numeric|min:0.0001',
            'reference'           => 'nullable|string|max:80',
            'include_in_inception'=> 'nullable|boolean',
            'memo'                => 'nullable|string',
            'lines'               => 'required|array|min:2',
            'lines.*.account_code'  => 'required|string',
            'lines.*.debit'         => 'nullable|numeric|min:0',
            'lines.*.credit'        => 'nullable|numeric|min:0',
            'lines.*.narration'     => 'nullable|string|max:255',
            'lines.*.counterparty'  => 'nullable|string|max:100',
            'lines.*.dimension_id'  => 'nullable|integer',
            'lines.*.dimension2_id' => 'nullable|integer',
        ]);

        // Validate balanced
        $totalDebit  = collect($data['lines'])->sum(fn ($l) => (float)($l['debit']  ?? 0));
        $totalCredit = collect($data['lines'])->sum(fn ($l) => (float)($l['credit'] ?? 0));
        if (abs($totalDebit - $totalCredit) > 0.01) {
            return ApiResponse::validationError(['lines' => "Journal is not balanced. Debits ({$totalDebit}) ≠ Credits ({$totalCredit})"]);
        }
        if ($totalDebit == 0) {
            return ApiResponse::validationError(['lines' => 'Journal has no amounts']);
        }

        return DB::transaction(function () use ($data, $request) {
            $jno  = $this->nextRef();
            $user = $request->user()?->user_id ?? 'system';

            $journalId = DB::table('journal_entries')->insertGetId([
                'journal_no'           => $jno,
                'journal_date'         => $data['journal_date'],
                'document_date'        => $data['document_date'] ?? $data['journal_date'],
                'event_date'           => $data['event_date'] ?? $data['journal_date'],
                'currency'             => $data['currency'] ?? 'KES',
                'exchange_rate'        => $data['exchange_rate'] ?? 1,
                'reference'            => $data['reference'] ?? null,
                'include_in_inception' => $data['include_in_inception'] ?? false,
                'memo'                 => $data['memo'] ?? null,
                'status'               => 'posted',
                'created_by'           => $user,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            $lineInserts = [];
            $glInserts   = [];
            $transNo     = $this->nextGlTransNo();

            foreach ($data['lines'] as $line) {
                $debit  = (float)($line['debit']  ?? 0);
                $credit = (float)($line['credit'] ?? 0);

                $lineInserts[] = [
                    'journal_id'    => $journalId,
                    'account_code'  => $line['account_code'],
                    'counterparty'  => $line['counterparty'] ?? null,
                    'dimension_id'  => $line['dimension_id'] ?? null,
                    'dimension2_id' => $line['dimension2_id'] ?? null,
                    'debit'         => $debit,
                    'credit'        => $credit,
                    'narration'     => $line['narration'] ?? null,
                ];

                // GL: positive = debit, negative = credit
                $amount = $debit > 0 ? $debit : -$credit;
                $glInserts[] = [
                    'type'          => self::GL_TYPE,
                    'trans_no'      => $transNo,
                    'tran_date'     => $data['journal_date'],
                    'account_code'  => $line['account_code'],
                    'reference'     => $jno,
                    'narration'     => $line['narration'] ?? ($data['memo'] ?? ''),
                    'amount'        => $amount,
                    'created_by'    => $user,
                    'dimension_id'  => $line['dimension_id'] ?? null,
                    'dimension2_id' => $line['dimension2_id'] ?? null,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }

            DB::table('journal_entry_lines')->insert($lineInserts);
            GlPostingService::post($glInserts);

            return ApiResponse::created(['id' => $journalId, 'journal_no' => $jno], 'Journal posted');
        });
    }

    public function void(int $id): JsonResponse
    {
        $journal = DB::table('journal_entries')->find($id);
        if (! $journal) return ApiResponse::notFound('Journal not found');
        if ($journal->status === 'voided') return ApiResponse::validationError(['status' => 'Already voided']);

        return DB::transaction(function () use ($journal) {
            DB::table('journal_entries')->where('id', $journal->id)->update(['status' => 'voided', 'updated_at' => now()]);

            // Reverse GL lines
            $orig    = DB::table('gld_transactions')->where('type', self::GL_TYPE)->where('reference', $journal->journal_no)->get();
            $transNo = $this->nextGlTransNo();
            if ($orig->isNotEmpty()) {
                GlPostingService::post($orig->map(fn ($l) => [
                    'type'          => self::GL_TYPE,
                    'trans_no'      => $transNo,
                    'tran_date'     => now()->toDateString(),
                    'account_code'  => $l->account_code,
                    'reference'     => 'VOID-' . $journal->journal_no,
                    'narration'     => 'VOID: ' . $l->narration,
                    'amount'        => -$l->amount,
                    'created_by'    => 'system',
                    'dimension_id'  => $l->dimension_id,
                    'dimension2_id' => $l->dimension2_id,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ])->toArray());
            }
            return ApiResponse::success(null, 'Journal voided');
        });
    }

    private function nextRef(): string
    {
        $ref    = DB::table('transaction_references')->where('trans_type', 'journal_entry')->first();
        $prefix = $ref?->prefix ?? 'JNL';
        $count  = DB::table('journal_entries')->count() + 1;
        return "{$prefix}/" . str_pad($count, 3, '0', STR_PAD_LEFT) . '/' . date('Y');
    }

    private function nextGlTransNo(): int
    {
        return (int)(DB::table('gld_transactions')->max('trans_no') ?? 0) + 1;
    }
}
