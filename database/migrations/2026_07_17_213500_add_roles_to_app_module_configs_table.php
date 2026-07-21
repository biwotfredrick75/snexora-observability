<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nullable = not yet configured, so the mobile app falls back to its
 * built-in per-module role list (module_registry.dart). Once an admin
 * sets this via Setup > App Modules, it becomes the authoritative
 * role gate for that module — no app redeploy needed to add/remove
 * a role's access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_module_configs', function (Blueprint $table) {
            $table->json('roles')->nullable()->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('app_module_configs', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
