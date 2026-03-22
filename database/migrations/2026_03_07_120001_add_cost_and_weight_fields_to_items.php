<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('standard_labour_cost',   15, 4)->default(0)->after('standard_cost');
            $table->decimal('standard_overhead_cost', 15, 4)->default(0)->after('standard_labour_cost');
            $table->decimal('pack_size_per_unit',     15, 4)->default(0)->after('standard_cost_memo');
            $table->decimal('weight_per_unit',        15, 4)->default(0)->after('pack_size_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['standard_labour_cost', 'standard_overhead_cost', 'pack_size_per_unit', 'weight_per_unit']);
        });
    }
};
