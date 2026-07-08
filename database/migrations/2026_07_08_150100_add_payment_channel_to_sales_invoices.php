<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            // Records which payment channel (a gl_accounts row tagged as a
            // payment channel — see gl_accounts.payment_provider) the customer
            // was directed to pay via. Reference only: does not itself move
            // money — inbound M-Pesa transactions are still reconciled through
            // the existing mpesa_payments/MpesaController flow.
            $table->string('payment_provider', 20)->nullable()->after('comments');
            $table->string('payment_channel_code', 20)->nullable()->after('payment_provider');

            $table->index('payment_channel_code');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['payment_channel_code']);
            $table->dropColumn(['payment_provider', 'payment_channel_code']);
        });
    }
};
