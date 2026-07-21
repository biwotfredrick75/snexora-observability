<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was a hardcoded 3% flat provision in WorkOrderService::complete() applied
 * to every BOM regardless of product — now configurable per BOM. Not set = 0
 * (no scrap provision), not the old flat 3%; each BOM must opt in explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->decimal('scrap_pct', 5, 2)->default(0.00)->after('batch_unit');
        });
    }

    public function down(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->dropColumn('scrap_pct');
        });
    }
};
