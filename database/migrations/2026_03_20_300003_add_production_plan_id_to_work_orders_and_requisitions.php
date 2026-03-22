<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('production_plan_id')->nullable()->after('id');
            $table->unsignedBigInteger('production_plan_item_id')->nullable()->after('production_plan_id');
        });

        Schema::table('stock_requisitions', function (Blueprint $table) {
            $table->unsignedBigInteger('production_plan_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['production_plan_id', 'production_plan_item_id']);
        });
        Schema::table('stock_requisitions', function (Blueprint $table) {
            $table->dropColumn('production_plan_id');
        });
    }
};
