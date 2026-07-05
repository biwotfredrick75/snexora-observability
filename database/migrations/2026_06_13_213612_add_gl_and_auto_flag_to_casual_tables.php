<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // GL account linkage on each earning/deduction type
        Schema::table('casual_earning_types', function (Blueprint $table) {
            $table->unsignedBigInteger('gl_account_id')->nullable()->after('sort_order');
            $table->foreign('gl_account_id')->references('id')->on('gl_accounts')->nullOnDelete();
        });

        // Distinguish auto-generated postings (from processWorker) vs manually-posted ones.
        // is_auto = true  → written during generation; deleted on re-generation
        // is_auto = false → written by Post-to-All / per-worker actions; survives re-generation
        Schema::table('casual_pay_postings', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('casual_earning_types', function (Blueprint $table) {
            $table->dropForeign(['gl_account_id']);
            $table->dropColumn('gl_account_id');
        });

        Schema::table('casual_pay_postings', function (Blueprint $table) {
            $table->dropColumn('is_auto');
        });
    }
};
