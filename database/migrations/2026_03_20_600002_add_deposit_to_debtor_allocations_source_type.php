<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE debtor_allocations MODIFY COLUMN source_type ENUM('credit_note','payment','deposit') NOT NULL DEFAULT 'credit_note'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE debtor_allocations MODIFY COLUMN source_type ENUM('credit_note','payment') NOT NULL DEFAULT 'credit_note'");
    }
};
