<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add mfg_type_id to boms so WOs can inherit it automatically
        Schema::table('boms', function (Blueprint $table) {
            $table->unsignedBigInteger('mfg_type_id')->nullable()->after('batch_unit');
            $table->foreign('mfg_type_id')->references('id')->on('manufacturing_types')->noActionOnDelete();
        });

        // Also allow mfg_type_id on work_orders to be set at creation (not only at release)
        // Column already exists from the earlier migration — nothing needed here.
    }

    public function down(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->dropForeign(['mfg_type_id']);
            $table->dropColumn('mfg_type_id');
        });
    }
};
