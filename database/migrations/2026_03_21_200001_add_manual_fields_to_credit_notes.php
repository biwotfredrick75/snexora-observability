<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('branch_id', 10)->nullable()->after('debtor_no');
            $table->string('customer_credit_ref', 60)->nullable()->after('branch_id');
            $table->unsignedInteger('sales_type_id')->nullable()->after('customer_credit_ref');
            $table->unsignedInteger('shipping_company_id')->nullable()->after('sales_type_id');
            $table->decimal('shipping_charge', 15, 2)->default(0)->after('shipping_company_id');
            $table->unsignedInteger('dimension_id')->nullable()->after('shipping_charge');
            $table->unsignedInteger('dimension2_id')->nullable()->after('dimension_id');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn([
                'branch_id', 'customer_credit_ref', 'sales_type_id',
                'shipping_company_id', 'shipping_charge', 'dimension_id', 'dimension2_id',
            ]);
        });
    }
};
