<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('item_code', 50)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('item_categories')->noActionOnDelete();
            $table->string('item_tax_type', 50)->nullable();
            $table->enum('item_type', ['Purchased', 'Manufactured', 'Service'])->default('Purchased');
            $table->string('units_of_measure', 50)->nullable();
            $table->string('supplier', 150)->nullable();

            // Dimensions
            $table->string('dimension1', 100)->nullable();
            $table->string('dimension2', 100)->nullable();

            // GL Accounts
            $table->string('sales_gl_account', 50)->nullable();
            $table->string('inventory_gl_account', 50)->nullable();
            $table->string('cogs_gl_account', 50)->nullable();
            $table->string('inventory_adjustments_gl_account', 50)->nullable();

            // Boolean flags
            $table->boolean('editable_description')->default(false);
            $table->boolean('exclude_from_sales')->default(false);
            $table->boolean('exclude_from_purchases')->default(false);
            $table->boolean('apply_batching')->default(false);
            $table->boolean('farmers_store_item')->default(false);
            $table->boolean('ai_item')->default(false);
            $table->boolean('treatment_item')->default(false);

            // Other
            $table->string('hs_code', 50)->nullable();
            $table->string('image_filename')->nullable();
            $table->string('bar_code', 100)->nullable();
            $table->enum('item_status', ['Active', 'Inactive'])->default('Active');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
