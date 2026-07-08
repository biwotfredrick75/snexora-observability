<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringJournalController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = DB::table('recurring_journal_templates')->orderByDesc('id')->get();
        $lines     = DB::table('recurring_journal_template_lines')
            ->whereIn('template_id', $templates->pluck('id'))
            ->orderBy('sort_order')
            ->get()
            ->groupBy('template_id');

        $templates->transform(function ($t) use ($lines) {
            $t->lines = ($lines[$t->id] ?? collect())->values();
            $t->total = $t->lines->sum('debit');
            return $t;
        });

        return ApiResponse::success($templates);
    }

    public function show(int $id): JsonResponse
    {
        $template = DB::table('recurring_journal_templates')->find($id);
        if (! $template) return ApiResponse::notFound('Template not found');
        $lines = DB::table('recurring_journal_template_lines')->where('template_id', $id)->orderBy('sort_order')->get();
        return ApiResponse::success(['template' => $template, 'lines' => $lines]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($data, $request) {
            $templateId = DB::table('recurring_journal_templates')->insertGetId([
                'template_no'   => $this->nextRef(),
                'description'   => $data['description'],
                'frequency'     => $data['frequency'],
                'start_date'    => $data['start_date'],
                'next_run_date' => $data['start_date'],
                'end_date'      => $data['end_date'] ?? null,
                'is_active'     => $data['is_active'] ?? true,
                'created_by'    => $request->user()?->user_id ?? 'system',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $this->insertLines($templateId, $data['lines']);

            return ApiResponse::created(['id' => $templateId], 'Recurring journal template created');
        });
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $template = DB::table('recurring_journal_templates')->find($id);
        if (! $template) return ApiResponse::notFound('Template not found');

        $data = $this->validated($request);

        return DB::transaction(function () use ($id, $data) {
            DB::table('recurring_journal_templates')->where('id', $id)->update([
                'description' => $data['description'],
                'frequency'   => $data['frequency'],
                'start_date'  => $data['start_date'],
                'end_date'    => $data['end_date'] ?? null,
                'is_active'   => $data['is_active'] ?? true,
                'updated_at'  => now(),
            ]);

            DB::table('recurring_journal_template_lines')->where('template_id', $id)->delete();
            $this->insertLines($id, $data['lines']);

            return ApiResponse::updated(null, 'Recurring journal template updated');
        });
    }

    public function destroy(int $id): JsonResponse
    {
        $template = DB::table('recurring_journal_templates')->find($id);
        if (! $template) return ApiResponse::notFound('Template not found');

        DB::table('recurring_journal_templates')->where('id', $id)->delete();
        return ApiResponse::deleted('Recurring journal template deleted');
    }

    /**
     * Generate draft journal entries for every active template whose next_run_date is due.
     * Generated journals land in the normal draft → approve → post workflow.
     */
    public function generateDue(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $due   = DB::table('recurring_journal_templates')
            ->where('is_active', true)
            ->where('next_run_date', '<=', $today)
            ->where(function ($q) use ($today) { $q->whereNull('end_date')->orWhere('end_date', '>=', $today); })
            ->get();

        $user      = $request->user()?->user_id ?? 'system';
        $generated = [];

        foreach ($due as $template) {
            $lines = DB::table('recurring_journal_template_lines')->where('template_id', $template->id)->get();
            if ($lines->isEmpty()) continue;

            DB::transaction(function () use ($template, $lines, $user, &$generated) {
                $jno       = $this->nextJournalRef();
                $journalId = DB::table('journal_entries')->insertGetId([
                    'journal_no'    => $jno,
                    'journal_date'  => now()->toDateString(),
                    'document_date' => now()->toDateString(),
                    'event_date'    => now()->toDateString(),
                    'currency'      => 'KES',
                    'exchange_rate' => 1,
                    'reference'     => "RECUR:{$template->template_no}",
                    'memo'          => $template->description,
                    'status'        => 'draft',
                    'created_by'    => $user,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                DB::table('journal_entry_lines')->insert($lines->map(fn ($l) => [
                    'journal_id'   => $journalId,
                    'account_code' => $l->account_code,
                    'debit'        => $l->debit,
                    'credit'       => $l->credit,
                    'narration'    => $l->narration ?: $template->description,
                ])->toArray());

                DB::table('recurring_journal_templates')->where('id', $template->id)->update([
                    'next_run_date' => $this->advance($template->next_run_date, $template->frequency),
                    'updated_at'    => now(),
                ]);

                $generated[] = ['template_no' => $template->template_no, 'journal_id' => $journalId, 'journal_no' => $jno];
            });
        }

        return ApiResponse::success(['generated' => count($generated), 'journals' => $generated],
            count($generated) . ' recurring journal(s) generated as drafts');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'description'           => 'required|string|max:255',
            'frequency'             => 'required|in:weekly,monthly,quarterly,yearly',
            'start_date'            => 'required|date',
            'end_date'              => 'nullable|date|after_or_equal:start_date',
            'is_active'             => 'nullable|boolean',
            'lines'                 => 'required|array|min:2',
            'lines.*.account_code'  => 'required|string',
            'lines.*.debit'         => 'nullable|numeric|min:0',
            'lines.*.credit'        => 'nullable|numeric|min:0',
            'lines.*.narration'     => 'nullable|string|max:255',
        ], [], []);
    }

    private function insertLines(int $templateId, array $lines): void
    {
        $totalDebit  = collect($lines)->sum(fn ($l) => (float) ($l['debit']  ?? 0));
        $totalCredit = collect($lines)->sum(fn ($l) => (float) ($l['credit'] ?? 0));
        if (abs($totalDebit - $totalCredit) > 0.01 || $totalDebit == 0) {
            abort(422, "Template lines are not balanced. Debits ({$totalDebit}) ≠ Credits ({$totalCredit})");
        }

        DB::table('recurring_journal_template_lines')->insert(
            collect($lines)->values()->map(fn ($l, $i) => [
                'template_id' => $templateId,
                'account_code'=> $l['account_code'],
                'debit'       => (float) ($l['debit']  ?? 0),
                'credit'      => (float) ($l['credit'] ?? 0),
                'narration'   => $l['narration'] ?? null,
                'sort_order'  => $i,
            ])->toArray()
        );
    }

    private function advance(string $date, string $frequency): string
    {
        $d = \Carbon\Carbon::parse($date);
        return match ($frequency) {
            'weekly'    => $d->addWeek()->toDateString(),
            'monthly'   => $d->addMonthNoOverflow()->toDateString(),
            'quarterly' => $d->addMonthsNoOverflow(3)->toDateString(),
            'yearly'    => $d->addYear()->toDateString(),
            default     => $d->addMonthNoOverflow()->toDateString(),
        };
    }

    private function nextRef(): string
    {
        $count = DB::table('recurring_journal_templates')->count() + 1;
        return 'RJT/' . str_pad((string) $count, 3, '0', STR_PAD_LEFT) . '/' . date('Y');
    }

    private function nextJournalRef(): string
    {
        $ref    = DB::table('transaction_references')->where('trans_type', 'journal_entry')->first();
        $prefix = $ref?->prefix ?? 'JNL';
        $count  = DB::table('journal_entries')->count() + 1;
        return "{$prefix}/" . str_pad((string) $count, 3, '0', STR_PAD_LEFT) . '/' . date('Y');
    }
}
