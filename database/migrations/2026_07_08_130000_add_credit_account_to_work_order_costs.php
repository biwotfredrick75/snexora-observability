<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Labour/overhead entries were cached as totals on the work order but never
 * posted to GL — there was no account to credit. Adding one makes each entry
 * capable of posting a real DR WIP / CR <credit_account> transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_labour', function (Blueprint $table) {
            $table->string('credit_account', 20)->nullable()->after('total_cost');
        });
        Schema::table('work_order_overhead', function (Blueprint $table) {
            $table->string('credit_account', 20)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_labour', function (Blueprint $table) {
            $table->dropColumn('credit_account');
        });
        Schema::table('work_order_overhead', function (Blueprint $table) {
            $table->dropColumn('credit_account');
        });
    }
};
