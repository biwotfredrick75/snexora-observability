<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix the legacy '0000-00-00 00:00:00' default that violates strict mode,
        // then add the new columns — all in a single ALTER TABLE to avoid re-triggering the error.
        DB::statement("
            ALTER TABLE `mpesa_payments`
                MODIFY COLUMN `transfer_time` timestamp NULL DEFAULT NULL,
                ADD COLUMN `debtor_no`  varchar(10)      NULL AFTER `allocated`,
                ADD COLUMN `payment_id` bigint unsigned   NULL AFTER `debtor_no`,
                ADD INDEX  `mpesa_debtor_idx` (`debtor_no`)
        ");

        // Zero-date rows → NULL (bypass strict mode for the comparison)
        DB::statement("SET SESSION sql_mode = ''");
        DB::statement("UPDATE `mpesa_payments` SET `transfer_time` = NULL WHERE CAST(`transfer_time` AS CHAR) = '0000-00-00 00:00:00'");
        DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE `mpesa_payments`
                MODIFY COLUMN `transfer_time` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
                DROP INDEX  `mpesa_debtor_idx`,
                DROP COLUMN `debtor_no`,
                DROP COLUMN `payment_id`
        ");
    }
};
