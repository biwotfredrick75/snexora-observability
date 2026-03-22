<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Disable FK checks so we can drop/recreate freely
        // FK checks not needed — dependent tables dropped first

        // Drop tables that reference items
        Schema::dropIfExists('item_reorder_levels');
        Schema::dropIfExists('item_conversion_items');
        Schema::dropIfExists('item_purchase_prices');
        Schema::dropIfExists('item_sales_prices');

        // Drop and recreate items with real schema
        Schema::dropIfExists('items');

        Schema::create('items', function (Blueprint $table) {
            $table->string('stock_id', 20)->primary();
            $table->unsignedInteger('category_id')->default(0);
            $table->unsignedInteger('tax_type_id')->default(0);
            $table->string('description', 200)->default('');
            $table->text('long_description')->nullable();
            $table->string('units', 20)->default('each');
            $table->char('mb_flag', 1)->default('B'); // B=Bought, M=Manufactured, S=Service
            // GL Accounts
            $table->string('sales_account', 15)->default('');
            $table->string('cogs_account', 15)->default('');
            $table->string('inventory_account', 15)->default('');
            $table->string('adjustment_account', 15)->default('');
            $table->string('wip_account', 15)->default(''); // Item assembly / WIP
            // Dimensions
            $table->unsignedInteger('dimension_id')->nullable();
            $table->unsignedInteger('dimension2_id')->nullable();
            // Costs
            $table->double('purchase_cost')->default(0);
            $table->double('material_cost')->default(0);
            $table->double('labour_cost')->default(0);
            $table->double('overhead_cost')->default(0);
            // Status flags
            $table->tinyInteger('inactive')->default(0);
            $table->tinyInteger('no_sale')->default(0);
            $table->tinyInteger('no_purchase')->default(0);
            $table->tinyInteger('editable')->default(0);
            // Depreciation (fixed assets)
            $table->char('depreciation_method', 1)->default('S');
            $table->double('depreciation_rate')->default(0);
            $table->double('depreciation_factor')->default(1);
            $table->date('depreciation_start')->nullable();
            $table->date('depreciation_date')->nullable();
            $table->string('fa_class_id', 20)->default('');
            // Other
            $table->string('bar_code', 100)->default('');
            $table->smallInteger('apply_batching')->default(0);
            $table->string('hs_code', 100)->default('');
            $table->double('material_weight')->default(0);
            $table->decimal('item_commission', 10, 2)->default(0);
            $table->integer('sell_in_pieces')->default(0);
            $table->string('sub_uoms', 250)->nullable();
            $table->integer('ai_item')->default(0);
            $table->integer('treatment_item')->default(0);
            $table->string('supplier_id', 100)->nullable();
            $table->string('image_filename')->nullable();
            $table->timestamps();
        });

        // Recreate nested tables with stock_id varchar FK
        Schema::create('item_sales_prices', function (Blueprint $table) {
            $table->id();
            $table->string('stock_id', 20);
            $table->string('currency', 50)->default('KES');
            $table->string('sales_type', 100)->default('');
            $table->decimal('price', 15, 4)->default(0);
            $table->timestamps();
            $table->index('stock_id');
        });

        Schema::create('item_purchase_prices', function (Blueprint $table) {
            $table->id();
            $table->string('stock_id', 20);
            $table->string('supplier', 150)->default('');
            $table->decimal('price', 15, 4)->default(0);
            $table->string('currency', 50)->default('KES');
            $table->string('supplier_uom', 50)->nullable();
            $table->decimal('conversion_factor', 15, 4)->default(1);
            $table->string('supplier_description', 255)->nullable();
            $table->timestamps();
            $table->index('stock_id');
        });

        Schema::create('item_reorder_levels', function (Blueprint $table) {
            $table->id();
            $table->string('stock_id', 20);
            $table->unsignedBigInteger('location_id');
            $table->decimal('reorder_level', 12, 4)->default(0);
            $table->timestamps();
            $table->unique(['stock_id', 'location_id']);
        });

        Schema::create('item_conversion_items', function (Blueprint $table) {
            $table->id();
            $table->string('stock_id', 20);
            $table->string('component_id', 20);
            $table->decimal('quantity_produced', 12, 4)->default(1);
            $table->timestamps();
            $table->index('stock_id');
        });

            }

    public function down(): void
    {
        // FK checks not needed — dependent tables dropped first
        Schema::dropIfExists('item_conversion_items');
        Schema::dropIfExists('item_reorder_levels');
        Schema::dropIfExists('item_purchase_prices');
        Schema::dropIfExists('item_sales_prices');
        Schema::dropIfExists('items');
            }
};
