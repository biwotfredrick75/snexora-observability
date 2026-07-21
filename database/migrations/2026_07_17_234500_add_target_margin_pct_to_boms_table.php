<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was a hardcoded 35% flat target used by WorkOrderController::costSheet()
 * to derive a suggested selling price, applied to every BOM regardless of
 * product. Default kept at 35 (matching prior behaviour) since — unlike
 * scrap_pct — a 0% margin default would make the suggested-price feature
 * useless out of the box; override per BOM as needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->decimal('target_margin_pct', 5, 2)->default(35.00)->after('scrap_pct');
        });
    }

    public function down(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->dropColumn('target_margin_pct');
        });
    }
};
