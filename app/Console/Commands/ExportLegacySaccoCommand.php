<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time export of the legacy Laravel sacco_* tables to JSON, for
 * nexora-sacco/cmd/importer to insert into the new Go service's own schema
 * (for the "tenant #1 = this ERP" organization). Read-only -- does not
 * modify or drop the local tables.
 *
 * php8.3 artisan sacco:export-legacy --output=storage/app/sacco-legacy-export.json
 */
class ExportLegacySaccoCommand extends Command
{
    protected $signature = 'sacco:export-legacy {--output=storage/app/sacco-legacy-export.json}';

    protected $description = 'Export legacy sacco_* tables to JSON for import into the standalone nexora-sacco Go service';

    public function handle(): int
    {
        $tables = [
            'sacco_members',
            'sacco_accounts',
            'sacco_transactions',
            'sacco_loan_products',
            'sacco_loans',
            'sacco_loan_guarantors',
            'sacco_loan_repayment_schedule',
        ];

        $export = [];
        foreach ($tables as $table) {
            $export[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
            $this->info(sprintf('%s: %d rows', $table, count($export[$table])));
        }

        // Farmer bio-data needed to populate the new service's Member rows
        // (full_name, phone) since it has no farmers table of its own.
        $farmerIds = collect($export['sacco_members'])->pluck('farmer_id')->filter()->unique()->values();
        $export['farmers'] = DB::table('farmers')
            ->whereIn('id', $farmerIds)
            ->select('id', 'farmer_no', 'full_name', 'phone')
            ->get()->map(fn ($row) => (array) $row)->all();

        $path = $this->option('output');
        file_put_contents(base_path($path), json_encode($export, JSON_PRETTY_PRINT));

        $this->info("Exported to {$path}");
        $this->info('Run on the nexora-sacco side: ./sacco-importer --input='.base_path($path).' --org-slug=<slug> [--dry-run]');

        return self::SUCCESS;
    }
}
