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
        Schema::table('milk_location_transfers', function (Blueprint $table) {
            // Groups multiple collection-point pickup legs into one
            // transporter run — set by the app on the first leg of a trip
            // and carried over onto every subsequent leg.
            $table->string('trip_ref', 30)->nullable()->after('id')->index();

            // The transporter hauling this leg — in this system transporters
            // are graders (see esp_farmer_sales' party_type='transporter'
            // usage), so this points at the same inventory_locations rows
            // used for grader_id elsewhere.
            $table->unsignedBigInteger('transporter_id')->nullable()->after('shift_id');
            $table->foreign('transporter_id')->references('id')->on('inventory_locations')->noActionOnDelete();

            // Dispatch-side weighing — net becomes the leg's `quantity`.
            $table->decimal('dispatch_gross_weight', 12, 3)->nullable()->after('quantity');
            $table->decimal('dispatch_tare_weight', 12, 3)->nullable()->after('dispatch_gross_weight');
            $table->decimal('dispatch_net_weight', 12, 3)->nullable()->after('dispatch_tare_weight');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('milk_location_transfers', function (Blueprint $table) {
            $table->dropForeign(['transporter_id']);
            $table->dropColumn([
                'trip_ref', 'transporter_id',
                'dispatch_gross_weight', 'dispatch_tare_weight', 'dispatch_net_weight',
            ]);
        });
    }
};
