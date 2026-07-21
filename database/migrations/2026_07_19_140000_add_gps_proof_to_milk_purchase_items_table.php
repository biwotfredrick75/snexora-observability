<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof-of-collection fields: the exact GPS location the grader was at when
 * THIS farmer's milk was weighed (captured per line item, not once for the
 * whole batch — a grader's route covers many farmers, so a single
 * batch-level location would misattribute everyone else's position to
 * whichever farmer happened to be weighed last). scale_connected records
 * whether the reading came from a verified Bluetooth scale vs manual entry,
 * and captured_at is the exact collection moment, distinct from created_at
 * (when the whole batch row was eventually inserted/synced, which can be
 * much later for offline collections).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_purchase_items', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('unique_key');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->decimal('gps_accuracy', 6, 2)->nullable()->after('longitude');
            $table->boolean('scale_connected')->default(false)->after('gps_accuracy');
            $table->string('scale_device', 20)->nullable()->after('scale_connected'); // 'ble' | 'classic' | 'manual'
            $table->timestamp('captured_at')->nullable()->after('scale_device');
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchase_items', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'gps_accuracy', 'scale_connected', 'scale_device', 'captured_at']);
        });
    }
};
