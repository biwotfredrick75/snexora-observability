<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── ESP Providers ─────────────────────────────────────────────────────
        if (! Schema::hasTable('esp_providers')) {
            Schema::create('esp_providers', function (Blueprint $table) {
                $table->id();
                $table->string('esp_code', 20)->unique();
                $table->string('name', 150);
                $table->string('contact_person', 100)->nullable();
                $table->string('phone', 30)->nullable();
                $table->string('email', 100)->nullable();
                $table->string('address', 255)->nullable();
                $table->decimal('credit_limit_pct', 5, 2)->default(50.00);
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->string('created_by', 60)->nullable();
                $table->timestamps();
                $table->index('status');
            });
        }

        // ── ESP → Farmer Sales ────────────────────────────────────────────────
        if (! Schema::hasTable('esp_farmer_sales')) {
            Schema::create('esp_farmer_sales', function (Blueprint $table) {
                $table->id();
                $table->string('sale_no', 30)->unique();
                $table->unsignedBigInteger('esp_id');
                $table->unsignedBigInteger('farmer_id');
                $table->date('sale_date');
                $table->decimal('total_amount', 15, 2);
                $table->decimal('deducted_amount', 15, 2)->default(0);
                $table->decimal('balance', 15, 2);
                $table->string('status', 20)->default('pending');
                $table->text('notes')->nullable();
                $table->string('created_by', 60)->nullable();
                $table->timestamps();
                $table->foreign('esp_id')->references('id')->on('esp_providers');
                $table->foreign('farmer_id')->references('id')->on('farmers');
                $table->index(['esp_id', 'status']);
                $table->index(['farmer_id', 'status']);
            });
        }

        if (! Schema::hasTable('esp_farmer_sale_items')) {
            Schema::create('esp_farmer_sale_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sale_id');
                $table->string('description', 255);
                $table->decimal('qty', 12, 3)->default(1);
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('total', 15, 2)->default(0);
                $table->foreign('sale_id')->references('id')->on('esp_farmer_sales')->cascadeOnDelete();
            });
        }

        // ── ESP → Company Purchases ───────────────────────────────────────────
        if (! Schema::hasTable('esp_company_purchases')) {
            Schema::create('esp_company_purchases', function (Blueprint $table) {
                $table->id();
                $table->string('purchase_no', 30)->unique();
                $table->unsignedBigInteger('esp_id');
                $table->date('purchase_date');
                $table->decimal('total_amount', 15, 2);
                $table->decimal('deducted_amount', 15, 2)->default(0);
                $table->decimal('balance', 15, 2);
                $table->string('status', 20)->default('pending');
                $table->text('notes')->nullable();
                $table->string('created_by', 60)->nullable();
                $table->timestamps();
                $table->foreign('esp_id')->references('id')->on('esp_providers');
                $table->index(['esp_id', 'status']);
            });
        }

        if (! Schema::hasTable('esp_company_purchase_items')) {
            Schema::create('esp_company_purchase_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_id');
                $table->string('description', 255);
                $table->decimal('qty', 12, 3)->default(1);
                $table->decimal('unit_price', 15, 2)->default(0);
                $table->decimal('total', 15, 2)->default(0);
                $table->foreign('purchase_id')->references('id')->on('esp_company_purchases')->cascadeOnDelete();
            });
        }

        // ── ESP Settlements ───────────────────────────────────────────────────
        if (! Schema::hasTable('esp_settlements')) {
            Schema::create('esp_settlements', function (Blueprint $table) {
                $table->id();
                $table->string('settlement_no', 30)->unique();
                $table->unsignedBigInteger('esp_id');
                $table->date('settlement_date');
                $table->date('period_from');
                $table->date('period_to');
                $table->decimal('farmer_collections', 15, 2)->default(0);
                $table->decimal('company_purchases_deducted', 15, 2)->default(0);
                $table->decimal('net_payable', 15, 2)->default(0);
                $table->decimal('actual_paid', 15, 2)->default(0);
                $table->string('status', 20)->default('draft');
                $table->text('notes')->nullable();
                $table->string('created_by', 60)->nullable();
                $table->timestamps();
                $table->foreign('esp_id')->references('id')->on('esp_providers');
                $table->index(['esp_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esp_settlements');
        Schema::dropIfExists('esp_company_purchase_items');
        Schema::dropIfExists('esp_company_purchases');
        Schema::dropIfExists('esp_farmer_sale_items');
        Schema::dropIfExists('esp_farmer_sales');
        Schema::dropIfExists('esp_providers');
    }
};
