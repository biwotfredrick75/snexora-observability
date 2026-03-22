<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the source_type enum to include 'payment'
        DB::statement("ALTER TABLE debtor_allocations MODIFY COLUMN source_type ENUM('credit_note','payment') NOT NULL DEFAULT 'credit_note'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE debtor_allocations MODIFY COLUMN source_type ENUM('credit_note') NOT NULL DEFAULT 'credit_note'");
    }
};
