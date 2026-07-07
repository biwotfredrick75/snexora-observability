<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an ESP provider to the login account that represents them, so the
 * agrovet/service-provider logs in themselves to record sales (rather than
 * any grader picking a provider from a list) — see EspController's
 * linkedProviderId() gating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esp_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('esp_code');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('esp_providers', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
