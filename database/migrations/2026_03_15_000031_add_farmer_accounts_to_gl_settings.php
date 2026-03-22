<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->string('farmers_payable_account', 20)->nullable()->after('payable_account')
                ->comment('Farmers milk payable / control account (DR when paying farmers)');
            $table->string('farmers_bank_account', 20)->nullable()->after('farmers_payable_account')
                ->comment('Bank account credited when farmer payments go out');
        });
    }

    public function down(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->dropColumn(['farmers_payable_account', 'farmers_bank_account']);
        });
    }
};
