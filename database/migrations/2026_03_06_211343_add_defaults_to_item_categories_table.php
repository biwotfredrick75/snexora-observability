<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            // Default values applied to new items in this category
            $table->string('item_tax_type', 50)->nullable()->after('description');
            $table->enum('item_type', ['Purchased', 'Manufactured', 'Service'])->default('Purchased')->after('item_tax_type');
            $table->string('units_of_measure', 50)->nullable()->after('item_type');
            $table->boolean('exclude_from_sales')->default(false)->after('units_of_measure');
            $table->boolean('exclude_from_purchases')->default(false)->after('exclude_from_sales');
            // GL Accounts
            $table->string('sales_gl_account', 50)->nullable()->after('exclude_from_purchases');
            $table->string('inventory_gl_account', 50)->nullable()->after('sales_gl_account');
            $table->string('cogs_gl_account', 50)->nullable()->after('inventory_gl_account');
            $table->string('inventory_adjustments_gl_account', 50)->nullable()->after('cogs_gl_account');
            $table->string('item_assembly_costs_gl_account', 50)->nullable()->after('inventory_adjustments_gl_account');
            // Dimensions
            $table->string('dimension1', 100)->nullable()->after('item_assembly_costs_gl_account');
            $table->string('dimension2', 100)->nullable()->after('dimension1');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_categories', function (Blueprint $table) {
            $table->dropColumn([
                'item_tax_type', 'item_type', 'units_of_measure',
                'exclude_from_sales', 'exclude_from_purchases',
                'sales_gl_account', 'inventory_gl_account', 'cogs_gl_account',
                'inventory_adjustments_gl_account', 'item_assembly_costs_gl_account',
                'dimension1', 'dimension2',
            ]);
        });
    }
};
