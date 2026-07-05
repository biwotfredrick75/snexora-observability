<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('route_id')->nullable()->after('capacity');
            $table->foreign('route_id')->references('id')->on('transport_routes')->nullOnDelete();
        });

        // NOTE: `status` (Active/Inactive) is intentionally NOT duplicated here —
        // the existing `inactive` boolean already conveys this; controllers derive
        // a display "status" string from it instead of storing a redundant column.
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
            $table->dropColumn('route_id');
        });
    }
};
