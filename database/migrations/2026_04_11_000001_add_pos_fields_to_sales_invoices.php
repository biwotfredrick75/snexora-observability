<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            // Drop the existing FK so we can make the column nullable
            $table->dropForeign(['debtor_no']);
            $table->string('debtor_no', 10)->nullable()->change();
            $table->foreign('debtor_no')->references('debtor_no')->on('customers')->noActionOnDelete();

            $table->string('customer_name', 120)->nullable()->after('debtor_no');
            $table->unsignedBigInteger('pos_trans_id')->nullable()->after('customer_name');
            $table->foreign('pos_trans_id')->references('id')->on('pos_transactions')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropForeign(['pos_trans_id']);
            $table->dropForeign(['debtor_no']);
            $table->dropColumn(['customer_name', 'pos_trans_id']);
            $table->string('debtor_no', 10)->nullable(false)->change();
            $table->foreign('debtor_no')->references('debtor_no')->on('customers');
        });
    }
};
