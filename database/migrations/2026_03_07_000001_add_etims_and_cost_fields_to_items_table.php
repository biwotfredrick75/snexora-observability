<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Standard Costs
            $table->decimal('standard_cost', 15, 4)->nullable()->after('bar_code');
            $table->string('standard_cost_reference', 100)->nullable()->after('standard_cost');
            $table->text('standard_cost_memo')->nullable()->after('standard_cost_reference');
            // ETIMS Item Registration
            $table->string('etims_item_name', 150)->nullable()->after('standard_cost_memo');
            $table->string('etims_stock_category', 50)->nullable()->after('etims_item_name');
            $table->string('etims_stock_class', 100)->nullable()->after('etims_stock_category');
            $table->string('etims_item_type', 50)->nullable()->after('etims_stock_class');
            $table->string('etims_origin_nation', 100)->nullable()->after('etims_item_type');
            $table->string('etims_packaging_unit', 50)->nullable()->after('etims_origin_nation');
            $table->string('etims_quantity_unit', 50)->nullable()->after('etims_packaging_unit');
            $table->string('etims_taxation_type', 50)->nullable()->after('etims_quantity_unit');
            $table->string('etims_item_code', 100)->nullable()->after('etims_taxation_type');
            $table->decimal('etims_price', 15, 4)->nullable()->after('etims_item_code');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn([
                'standard_cost', 'standard_cost_reference', 'standard_cost_memo',
                'etims_item_name', 'etims_stock_category', 'etims_stock_class',
                'etims_item_type', 'etims_origin_nation', 'etims_packaging_unit',
                'etims_quantity_unit', 'etims_taxation_type', 'etims_item_code', 'etims_price',
            ]);
        });
    }
};
