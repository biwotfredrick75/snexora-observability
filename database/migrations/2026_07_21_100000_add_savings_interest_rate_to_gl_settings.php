<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->decimal('sacco_savings_interest_rate_pct', 5, 2)->nullable()
                ->after('sacco_cash_account')
                ->comment('Annual rate used to project a member\'s savings earnings — not an actual declared dividend, just an estimate shown to members.');
        });
    }

    public function down(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->dropColumn('sacco_savings_interest_rate_pct');
        });
    }
};
