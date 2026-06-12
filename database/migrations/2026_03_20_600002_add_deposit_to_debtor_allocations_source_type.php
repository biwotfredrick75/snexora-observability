<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                DECLARE @cn NVARCHAR(200);
                SELECT @cn = name FROM sys.check_constraints
                WHERE parent_object_id = OBJECT_ID('debtor_allocations')
                  AND CHARINDEX('source_type', definition) > 0;
                IF @cn IS NOT NULL EXEC('ALTER TABLE debtor_allocations DROP CONSTRAINT [' + @cn + ']');
            ");
            DB::statement("ALTER TABLE debtor_allocations ADD CONSTRAINT chk_da_source_type CHECK (source_type IN ('credit_note','payment','deposit'))");
        } else {
            DB::statement("ALTER TABLE debtor_allocations MODIFY COLUMN source_type ENUM('credit_note','payment','deposit') NOT NULL DEFAULT 'credit_note'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                DECLARE @cn NVARCHAR(200);
                SELECT @cn = name FROM sys.check_constraints
                WHERE parent_object_id = OBJECT_ID('debtor_allocations')
                  AND CHARINDEX('source_type', definition) > 0;
                IF @cn IS NOT NULL EXEC('ALTER TABLE debtor_allocations DROP CONSTRAINT [' + @cn + ']');
            ");
            DB::statement("ALTER TABLE debtor_allocations ADD CONSTRAINT chk_da_source_type CHECK (source_type IN ('credit_note','payment'))");
        } else {
            DB::statement("ALTER TABLE debtor_allocations MODIFY COLUMN source_type ENUM('credit_note','payment') NOT NULL DEFAULT 'credit_note'");
        }
    }
};
