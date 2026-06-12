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
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'etims_item_name')) {
                $table->string('etims_item_name', 200)->nullable()->after('etims_quantity_unit');
            }
            if (!Schema::hasColumn('items', 'etims_stock_category')) {
                $table->string('etims_stock_category', 5)->nullable()->after('etims_item_name');
            }
            if (!Schema::hasColumn('items', 'etims_origin_nation')) {
                $table->string('etims_origin_nation', 5)->nullable()->after('etims_stock_category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $cols = array_values(array_filter(
                ['etims_item_name', 'etims_stock_category', 'etims_origin_nation'],
                fn($c) => Schema::hasColumn('items', $c)
            ));
            if ($cols) $table->dropColumn($cols);
        });
    }
};
