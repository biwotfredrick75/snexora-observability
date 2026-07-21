<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PosController and MpesaController already read pos_cash_account /
 * pos_card_account / pos_mpesa_account off gl_settings, but the columns were
 * never added — every POS sale has silently been falling back to the literal
 * strings 'CASH' / 'BANK' / 'MPESA', which are not real account codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->string('pos_cash_account', 20)->nullable()->after('receivable_account');
            $table->string('pos_card_account', 20)->nullable()->after('pos_cash_account');
            $table->string('pos_mpesa_account', 20)->nullable()->after('pos_card_account');
        });
    }

    public function down(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->dropColumn(['pos_cash_account', 'pos_card_account', 'pos_mpesa_account']);
        });
    }
};
