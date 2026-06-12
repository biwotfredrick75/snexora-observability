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
                WHERE parent_object_id = OBJECT_ID('credit_notes')
                  AND CHARINDEX('cn_type', definition) > 0;
                IF @cn IS NOT NULL EXEC('ALTER TABLE credit_notes DROP CONSTRAINT [' + @cn + ']');
            ");
            DB::statement("ALTER TABLE credit_notes ADD CONSTRAINT chk_cn_type CHECK (cn_type IN ('return','return_to_store','damage','discount','write_off'))");
        } else {
            DB::statement("ALTER TABLE credit_notes MODIFY COLUMN cn_type ENUM('return','return_to_store','damage','discount','write_off') NOT NULL DEFAULT 'return_to_store'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("
                DECLARE @cn NVARCHAR(200);
                SELECT @cn = name FROM sys.check_constraints
                WHERE parent_object_id = OBJECT_ID('credit_notes')
                  AND CHARINDEX('cn_type', definition) > 0;
                IF @cn IS NOT NULL EXEC('ALTER TABLE credit_notes DROP CONSTRAINT [' + @cn + ']');
            ");
            DB::statement("ALTER TABLE credit_notes ADD CONSTRAINT chk_cn_type CHECK (cn_type IN ('return','discount','write_off'))");
        } else {
            DB::statement("ALTER TABLE credit_notes MODIFY COLUMN cn_type ENUM('return','discount','write_off') NOT NULL DEFAULT 'return'");
        }
    }
};
