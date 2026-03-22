<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedInteger('customer_number')->nullable()->after('debtor_no');
            $table->string('kra_pin', 20)->nullable()->after('email');
            $table->string('currency', 10)->default('KES')->after('kra_pin');
            $table->unsignedBigInteger('credit_status_id')->nullable()->after('credit_limit');
            $table->integer('credit_invoices_allowed')->default(0)->after('credit_status_id');
            $table->decimal('prompt_payment_discount', 5, 2)->default(0)->after('credit_invoices_allowed');
            $table->text('general_notes')->nullable()->after('prompt_payment_discount');
            $table->unsignedBigInteger('dimension_id')->nullable()->after('general_notes');
            $table->unsignedBigInteger('dimension2_id')->nullable()->after('dimension_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'customer_number',
                'kra_pin',
                'currency',
                'credit_status_id',
                'credit_invoices_allowed',
                'prompt_payment_discount',
                'general_notes',
                'dimension_id',
                'dimension2_id',
            ]);
        });
    }
};
