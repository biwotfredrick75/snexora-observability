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
        // Fix invalid '0000-00-00' defaults (MySQL strict mode only)
        if (\DB::getDriverName() === 'mysql') {
            \DB::statement("ALTER TABLE items
                MODIFY depreciation_start date NULL DEFAULT NULL,
                MODIFY depreciation_date  date NULL DEFAULT NULL");
        }

        Schema::table('items', function (Blueprint $table) {
            $table->string('etims_item_type', 5)->nullable()->after('description');
            $table->string('etims_taxation_type', 5)->nullable()->after('etims_item_type');
            $table->string('etims_item_code', 50)->nullable()->after('etims_taxation_type');
            $table->string('etims_stock_class', 10)->nullable()->after('etims_item_code');
            $table->string('etims_packaging_unit', 10)->nullable()->after('etims_stock_class');
            $table->string('etims_quantity_unit', 10)->nullable()->after('etims_packaging_unit');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn([
                'etims_item_type', 'etims_taxation_type', 'etims_item_code',
                'etims_stock_class', 'etims_packaging_unit', 'etims_quantity_unit',
            ]);
        });
    }
};
