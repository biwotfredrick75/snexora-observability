<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_preferences', function (Blueprint $table) {
            $table->id();

            // General Information
            $table->string('name', 255)->default('');
            $table->text('address')->nullable();
            $table->string('domicile', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('fax', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('bcc_email', 255)->nullable();
            $table->string('company_number', 100)->nullable();
            $table->string('kra_pin', 50)->nullable();
            $table->string('currency', 100)->default('Kenya Shillings');
            $table->string('logo_filename', 255)->nullable();

            // System Preferences
            $table->boolean('auto_revaluation')->default(true);
            $table->boolean('timezone_on_reports')->default(true);
            $table->boolean('logo_on_reports')->default(true);
            $table->boolean('barcodes_on_stocks')->default(false);
            $table->boolean('auto_increase_refs')->default(true);

            // General Ledger & Fiscal
            $table->string('fiscal_year', 100)->nullable();
            $table->string('fiscal_status', 20)->default('Active');
            $table->unsignedTinyInteger('tax_periods')->default(1);
            $table->unsignedTinyInteger('tax_last_period')->default(1);
            $table->boolean('alt_tax_on_docs')->default(false);
            $table->boolean('suppress_tax_rates')->default(false);

            // Sales Pricing
            $table->string('base_price_calc', 100)->default('No base price list');
            $table->decimal('add_price_from_std_cost', 8, 2)->nullable();
            $table->unsignedTinyInteger('round_prices')->default(2);

            // Optional Modules
            $table->boolean('module_manufacturing')->default(false);
            $table->boolean('module_fixed_assets')->default(false);
            $table->unsignedTinyInteger('use_dimensions')->default(0);

            // User Interface Options
            $table->boolean('short_name_in_list')->default(false);
            $table->boolean('search_item_list')->default(false);
            $table->boolean('search_customer_list')->default(false);
            $table->boolean('search_supplier_list')->default(false);
            $table->unsignedSmallInteger('login_timeout')->default(2000);

            $table->string('db_scheme_version', 20)->default('2.4.1');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_preferences');
    }
};
