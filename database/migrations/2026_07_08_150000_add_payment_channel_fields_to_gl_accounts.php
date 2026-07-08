<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an existing GL/bank account double as a customer-facing payment
 * channel (e.g. an M-Pesa Paybill/Till, or in future a bank account used
 * for direct transfers) that can be offered as a payment method when
 * placing a sales invoice.
 *
 * No new "payment channels" table — a payment channel IS a gl_accounts
 * row, tagged with a provider + its provider-specific identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_accounts', function (Blueprint $table) {
            // 'mpesa' | 'bank' | 'cash' | 'cheque' | null (not a payment channel)
            $table->string('payment_provider', 20)->nullable()->after('tags');
            // Paybill/Till (BusinessShortCode) for provider = 'mpesa'.
            // Bank accounts don't need a separate number here — the gl_accounts
            // row's own `code`/`name` already identify the account.
            $table->string('mpesa_shortcode', 20)->nullable()->after('payment_provider');

            $table->index('payment_provider');
        });
    }

    public function down(): void
    {
        Schema::table('gl_accounts', function (Blueprint $table) {
            $table->dropIndex(['payment_provider']);
            $table->dropColumn(['payment_provider', 'mpesa_shortcode']);
        });
    }
};
