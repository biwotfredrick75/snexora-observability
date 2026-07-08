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
            'save_as_draft'       => 'nullable|boolean',
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

        $asDraft = (bool) ($data['save_as_draft'] ?? false);

        return DB::transaction(function () use ($data, $request, $asDraft) {
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
                'status'               => $asDraft ? 'draft' : 'posted',
                'posted_by'            => $asDraft ? null : $user,
                'posted_at'            => $asDraft ? null : now(),
                'created_by'           => $user,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            $lineInserts = [];
            foreach ($data['lines'] as $line) {
                $lineInserts[] = [
                    'journal_id'    => $journalId,
                    'account_code'  => $line['account_code'],
                    'counterparty'  => $line['counterparty'] ?? null,
                    'dimension_id'  => $line['dimension_id'] ?? null,
                    'dimension2_id' => $line['dimension2_id'] ?? null,
                    'debit'         => (float)($line['debit']  ?? 0),
                    'credit'        => (float)($line['credit'] ?? 0),
                    'narration'     => $line['narration'] ?? null,
                ];
            }
            DB::table('journal_entry_lines')->insert($lineInserts);

            if (! $asDraft) {
                $this->postLinesToGl($journalId, $jno, $data['journal_date'], $data['memo'] ?? '', $user);
            }

            return ApiResponse::created(
                ['id' => $journalId, 'journal_no' => $jno],
                $asDraft ? 'Journal saved as draft' : 'Journal posted'
            );
        });
    }

    // ── Approval / posting workflow ─────────────────────────────────────────────

    public function pendingApproval(): JsonResponse
    {
        return ApiResponse::success(
            DB::table('journal_entries')->where('status', 'draft')->orderByDesc('id')->get()
        );
    }

    public function approve(int $id, Request $request): JsonResponse
    {
        $journal = DB::table('journal_entries')->find($id);
        if (! $journal) return ApiResponse::notFound('Journal not found');
        if ($journal->status !== 'draft') return ApiResponse::validationError(['status' => 'Only draft journals can be approved']);

        DB::table('journal_entries')->where('id', $id)->update([
            'status'      => 'approved',
            'approved_by' => $request->user()?->user_id ?? 'system',
            'approved_at' => now(),
            'updated_at'  => now(),
        ]);
        return ApiResponse::success(null, 'Journal approved');
    }

    public function reject(int $id, Request $request): JsonResponse
    {
        $data    = $request->validate(['reason' => 'nullable|string|max:500']);
        $journal = DB::table('journal_entries')->find($id);
        if (! $journal) return ApiResponse::notFound('Journal not found');
        if ($journal->status !== 'draft') return ApiResponse::validationError(['status' => 'Only draft journals can be rejected']);

        DB::table('journal_entries')->where('id', $id)->update([
            'status'          => 'rejected',
            'rejected_reason' => $data['reason'] ?? null,
            'updated_at'      => now(),
        ]);
        return ApiResponse::success(null, 'Journal rejected');
    }

    public function pendingPost(): JsonResponse
    {
        return ApiResponse::success(
            DB::table('journal_entries')->where('status', 'approved')->orderByDesc('id')->get()
        );
    }

    public function post(int $id, Request $request): JsonResponse
    {
        $journal = DB::table('journal_entries')->find($id);
        if (! $journal) return ApiResponse::notFound('Journal not found');
        if ($journal->status !== 'approved') return ApiResponse::validationError(['status' => 'Only approved journals can be posted']);

        $user = $request->user()?->user_id ?? 'system';
        DB::transaction(function () use ($journal, $user) {
            $this->postLinesToGl($journal->id, $journal->journal_no, $journal->journal_date, $journal->memo ?? '', $user);
            DB::table('journal_entries')->where('id', $journal->id)->update([
                'status'     => 'posted',
                'posted_by'  => $user,
                'posted_at'  => now(),
                'updated_at' => now(),
            ]);
        });
        return ApiResponse::success(null, 'Journal posted');
    }

    public function postBulk(Request $request): JsonResponse
    {
        $data  = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer']);
        $user  = $request->user()?->user_id ?? 'system';
        $posted = 0;
        $failed = [];

        foreach ($data['ids'] as $id) {
            $journal = DB::table('journal_entries')->find($id);
            if (! $journal || $journal->status !== 'approved') { $failed[] = $id; continue; }

            DB::transaction(function () use ($journal, $user) {
                $this->postLinesToGl($journal->id, $journal->journal_no, $journal->journal_date, $journal->memo ?? '', $user);
                DB::table('journal_entries')->where('id', $journal->id)->update([
                    'status' => 'posted', 'posted_by' => $user, 'posted_at' => now(), 'updated_at' => now(),
                ]);
            });
            $posted++;
        }

        return ApiResponse::success(['posted' => $posted, 'failed' => $failed], "{$posted} journal(s) posted");
    }

    private function postLinesToGl(int $journalId, string $jno, string $journalDate, string $memo, string $user): void
    {
        $lines   = DB::table('journal_entry_lines')->where('journal_id', $journalId)->get();
        $transNo = $this->nextGlTransNo();

        $glInserts = $lines->map(function ($line) use ($transNo, $journalDate, $jno, $memo, $user) {
            $amount = $line->debit > 0 ? (float) $line->debit : -(float) $line->credit;
            return [
                'type'          => self::GL_TYPE,
                'trans_no'      => $transNo,
                'tran_date'     => $journalDate,
                'account_code'  => $line->account_code,
                'reference'     => $jno,
                'narration'     => $line->narration ?: $memo,
                'amount'        => $amount,
                'created_by'    => $user,
                'dimension_id'  => $line->dimension_id,
                'dimension2_id' => $line->dimension2_id,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        })->toArray();

        GlPostingService::post($glInserts);
    }

    public function void(int $id, Request $request): JsonResponse
    {
        $data    = $request->validate(['reason' => 'nullable|string|max:500']);
        $journal = DB::table('journal_entries')->find($id);
        if (! $journal) return ApiResponse::notFound('Journal not found');
        if ($journal->status !== 'posted') return ApiResponse::validationError(['status' => 'Only posted journals can be reversed']);

        return DB::transaction(function () use ($journal, $data, $request) {
            DB::table('journal_entries')->where('id', $journal->id)->update([
                'status'      => 'voided',
                'voided_by'   => $request->user()?->user_id ?? 'system',
                'void_reason' => $data['reason'] ?? null,
                'updated_at'  => now(),
            ]);

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
