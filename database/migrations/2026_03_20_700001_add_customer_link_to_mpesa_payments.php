<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mpesa_payments')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            Schema::table('mpesa_payments', function (Blueprint $table) {
                $table->timestamp('transfer_time')->nullable()->change();
                $table->string('debtor_no', 10)->nullable();
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->index('debtor_no', 'mpesa_debtor_idx');
            });
        } else {
            DB::statement("
                ALTER TABLE `mpesa_payments`
                    MODIFY COLUMN `transfer_time` timestamp NULL DEFAULT NULL,
                    ADD COLUMN `debtor_no`  varchar(10)      NULL AFTER `allocated`,
                    ADD COLUMN `payment_id` bigint unsigned   NULL AFTER `debtor_no`,
                    ADD INDEX  `mpesa_debtor_idx` (`debtor_no`)
            ");
            DB::statement("SET SESSION sql_mode = ''");
            DB::statement("UPDATE `mpesa_payments` SET `transfer_time` = NULL WHERE CAST(`transfer_time` AS CHAR) = '0000-00-00 00:00:00'");
            DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('mpesa_payments')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'sqlsrv') {
            Schema::table('mpesa_payments', function (Blueprint $table) {
                $table->dropIndex('mpesa_debtor_idx');
                $table->dropColumn(['debtor_no', 'payment_id']);
            });
        } else {
            DB::statement("
                ALTER TABLE `mpesa_payments`
                    MODIFY COLUMN `transfer_time` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
                    DROP INDEX  `mpesa_debtor_idx`,
                    DROP COLUMN `debtor_no`,
                    DROP COLUMN `payment_id`
            ");
        }
    }
};
