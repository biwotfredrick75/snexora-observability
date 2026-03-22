<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_branches', function (Blueprint $table) {
            $table->string('branch_short_name', 50)->nullable()->after('branch_name');
            $table->unsignedBigInteger('sales_person_id')->nullable()->after('branch_short_name');
            $table->unsignedBigInteger('sales_area_id')->nullable()->after('sales_person_id');
            $table->unsignedBigInteger('sales_group_id')->nullable()->after('sales_area_id');
            $table->unsignedBigInteger('location_id')->nullable()->after('sales_group_id');
            $table->unsignedBigInteger('shipping_company_id')->nullable()->after('location_id');
            $table->unsignedBigInteger('tax_group_id')->nullable()->after('shipping_company_id');
            $table->string('sales_account', 15)->nullable()->after('tax_group_id');
            $table->string('sales_discount_account', 15)->nullable()->after('sales_account');
            $table->string('ar_account', 15)->nullable()->after('sales_discount_account');
            $table->string('prompt_discount_account', 15)->nullable()->after('ar_account');
            $table->string('bank_account_number', 50)->nullable()->after('prompt_discount_account');
            $table->string('contact_person', 100)->nullable()->after('bank_account_number');
            $table->string('secondary_phone', 30)->nullable()->after('contact_person');
            $table->string('fax', 30)->nullable()->after('secondary_phone');
            $table->string('email', 100)->nullable()->after('fax');
            $table->string('document_language', 30)->default('Customer default')->after('email');
            $table->text('mailing_address')->nullable()->after('document_language');
            $table->text('billing_address')->nullable()->after('mailing_address');
            $table->text('general_notes')->nullable()->after('billing_address');
        });
    }

    public function down(): void
    {
        Schema::table('customer_branches', function (Blueprint $table) {
            $table->dropColumn([
                'branch_short_name',
                'sales_person_id',
                'sales_area_id',
                'sales_group_id',
                'location_id',
                'shipping_company_id',
                'tax_group_id',
                'sales_account',
                'sales_discount_account',
                'ar_account',
                'prompt_discount_account',
                'bank_account_number',
                'contact_person',
                'secondary_phone',
                'fax',
                'email',
                'document_language',
                'mailing_address',
                'billing_address',
                'general_notes',
            ]);
        });
    }
};
