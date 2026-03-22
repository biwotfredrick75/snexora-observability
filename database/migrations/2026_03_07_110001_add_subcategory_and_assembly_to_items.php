<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('sub_category', 100)->nullable()->after('category_id');
            $table->string('item_assembly_costs_gl_account', 50)->nullable()->after('inventory_adjustments_gl_account');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['sub_category', 'item_assembly_costs_gl_account']);
        });
    }
};
