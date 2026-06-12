<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Scans gld_transactions and journal_entry_lines for balance violations.
 *
 * A balanced GL transaction: SUM(amount) for a (type, trans_no) group = 0
 * A balanced journal:        SUM(debit) = SUM(credit) in journal_entry_lines
 */
class GlIntegrityService
{
    private const TOLERANCE = 0.005;

    private static array $typeLabels = [
        0  => 'Journal Entry',
        1  => 'Bank Deposit',
        2  => 'Bank Payment',
        4  => 'Funds Transfer',
        10 => 'Sales Invoice',
        11 => 'Credit Note',
        12 => 'Customer Payment',
        13 => 'Customer Deposit',
        20 => 'Supplier Invoice',
        21 => 'Supplier Credit',
        22 => 'Supplier Payment',
        25 => 'Purchase Order',
        26 => 'Delivery Note',
        32 => 'Location Transfer',
        35 => 'Inventory Adjustment',
        40 => 'Work Order',
        41 => 'Work Order Issue',
        50 => 'Payroll',
        60 => 'Petty Cash',
    ];

    public static function check(): array
    {
        // ── 1. GL transaction groups ──────────────────────────────────────────
        $totalGroups = (int) DB::table('gld_transactions')
            ->selectRaw("COUNT(DISTINCT CONCAT(type, '_', trans_no)) as cnt")
            ->value('cnt');

        $imbalanced = DB::table('gld_transactions')
            ->selectRaw('type, trans_no, SUM(amount) as balance, COUNT(*) as line_count, MIN(tran_date) as tran_date, MIN(reference) as reference')
            ->groupBy('type', 'trans_no')
            ->havingRaw('ABS(SUM(amount)) > ?', [self::TOLERANCE])
            ->orderBy('tran_date')
            ->get()
            ->map(fn ($r) => [
                'type'       => $r->type,
                'type_label' => self::$typeLabels[$r->type] ?? "Type {$r->type}",
                'trans_no'   => $r->trans_no,
                'tran_date'  => $r->tran_date,
                'reference'  => $r->reference,
                'line_count' => $r->line_count,
                'balance'    => round((float) $r->balance, 4),
            ])
            ->toArray();

        // ── 2. Journal entry_lines balance (debit = credit per journal) ───────
        $journalImbalanced = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'je.id', '=', 'jel.journal_id')
            ->selectRaw('je.id, je.journal_no, je.journal_date, je.status,
                         SUM(jel.debit) as total_debit,
                         SUM(jel.credit) as total_credit,
                         ABS(SUM(jel.debit) - SUM(jel.credit)) as difference')
            ->groupBy('je.id', 'je.journal_no', 'je.journal_date', 'je.status')
            ->havingRaw('ABS(SUM(jel.debit) - SUM(jel.credit)) > ?', [self::TOLERANCE])
            ->orderBy('je.journal_date')
            ->get()
            ->map(fn ($r) => [
                'id'          => $r->id,
                'journal_no'  => $r->journal_no,
                'journal_date'=> $r->journal_date,
                'status'      => $r->status,
                'total_debit' => round((float) $r->total_debit, 2),
                'total_credit'=> round((float) $r->total_credit, 2),
                'difference'  => round((float) $r->difference, 4),
            ])
            ->toArray();

        // ── 3. Summary by type ────────────────────────────────────────────────
        $byType = DB::table('gld_transactions')
            ->selectRaw('type, COUNT(DISTINCT trans_no) as total_groups, SUM(amount) as type_balance')
            ->groupBy('type')
            ->orderBy('type')
            ->get()
            ->map(fn ($r) => [
                'type'         => $r->type,
                'type_label'   => self::$typeLabels[$r->type] ?? "Type {$r->type}",
                'total_groups' => $r->total_groups,
                'type_balance' => round((float) $r->type_balance, 4),
                'balanced'     => abs((float) $r->type_balance) <= self::TOLERANCE,
            ])
            ->toArray();

        // ── 4. Overall GL balance (whole ledger must also net to zero) ────────
        $overallBalance = (float) DB::table('gld_transactions')->sum('amount');

        return [
            'is_clean'              => empty($imbalanced) && empty($journalImbalanced),
            'overall_gl_balance'    => round($overallBalance, 4),
            'overall_gl_balanced'   => abs($overallBalance) <= self::TOLERANCE,
            'total_transaction_groups' => $totalGroups,
            'imbalanced_groups'     => $imbalanced,
            'imbalanced_group_count'=> count($imbalanced),
            'journal_imbalanced'    => $journalImbalanced,
            'journal_imbalanced_count' => count($journalImbalanced),
            'summary_by_type'       => $byType,
            'checked_at'            => now()->toDateTimeString(),
        ];
    }
}
