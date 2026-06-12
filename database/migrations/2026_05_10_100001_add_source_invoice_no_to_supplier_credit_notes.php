<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->string('source_invoice_no', 50)->default('')->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_credit_notes', function (Blueprint $table) {
            $table->dropColumn('source_invoice_no');
        });
    }
};
