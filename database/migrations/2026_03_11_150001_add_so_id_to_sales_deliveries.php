<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('so_id')->nullable()->after('id');
            $table->foreign('so_id')->references('id')->on('sales_orders')->noActionOnDelete();
        });

        Schema::table('sales_delivery_items', function (Blueprint $table) {
            $table->unsignedBigInteger('so_item_id')->nullable()->after('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_delivery_items', function (Blueprint $table) {
            $table->dropColumn('so_item_id');
        });
        Schema::table('sales_deliveries', function (Blueprint $table) {
            $table->dropForeign(['so_id']);
            $table->dropColumn('so_id');
        });
    }
};
