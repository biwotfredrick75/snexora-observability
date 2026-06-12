<?php

namespace App\Console\Commands\Banking;

use App\Services\GlIntegrityService;
use Illuminate\Console\Command;

class GlCheckCommand extends Command
{
    protected $signature   = 'gl:check {--fix : Auto-flag imbalanced entries in output}';
    protected $description = 'Check GL transaction balance integrity (SUM(amount)=0 per group, journals balanced)';

    public function handle(): int
    {
        $this->info('Running GL integrity check…');
        $result = GlIntegrityService::check();

        $this->newLine();
        $this->line("  Total transaction groups : <fg=cyan>{$result['total_transaction_groups']}</>");
        $this->line('  Overall GL balance       : <fg='.($result['overall_gl_balanced'] ? 'green' : 'red').'>'
            . number_format($result['overall_gl_balance'], 4).'</>');

        // Imbalanced GL groups
        if (empty($result['imbalanced_groups'])) {
            $this->info('  ✓ All GL transaction groups are balanced.');
        } else {
            $this->error("  ✗ {$result['imbalanced_group_count']} imbalanced GL group(s) found:");
            $this->table(
                ['Type', 'Type Label', 'Trans No', 'Date', 'Reference', 'Lines', 'Balance'],
                array_map(fn ($r) => [
                    $r['type'], $r['type_label'], $r['trans_no'],
                    $r['tran_date'], $r['reference'], $r['line_count'],
                    number_format($r['balance'], 4),
                ], $result['imbalanced_groups'])
            );
        }

        // Imbalanced journals
        if (empty($result['journal_imbalanced'])) {
            $this->info('  ✓ All journal entries are balanced (debit = credit).');
        } else {
            $this->error("  ✗ {$result['journal_imbalanced_count']} unbalanced journal(s):");
            $this->table(
                ['Journal No', 'Date', 'Status', 'Total Debit', 'Total Credit', 'Difference'],
                array_map(fn ($r) => [
                    $r['journal_no'], $r['journal_date'], $r['status'],
                    number_format($r['total_debit'], 2),
                    number_format($r['total_credit'], 2),
                    number_format($r['difference'], 4),
                ], $result['journal_imbalanced'])
            );
        }

        // Summary by type
        $this->newLine();
        $this->line('  <fg=yellow>Summary by transaction type:</>');
        $this->table(
            ['Type', 'Label', 'Groups', 'Net Balance', 'OK?'],
            array_map(fn ($r) => [
                $r['type'], $r['type_label'], $r['total_groups'],
                number_format($r['type_balance'], 4),
                $r['balanced'] ? '✓' : '✗',
            ], $result['summary_by_type'])
        );

        $this->newLine();
        if ($result['is_clean']) {
            $this->info('  ✓ GL integrity OK — all entries balance.');
            return Command::SUCCESS;
        }

        $this->error('  ✗ GL integrity issues found — review the entries above.');
        return Command::FAILURE;
    }
}
