<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->decimal('default_tare_kg', 6, 2)->default(0.10)->after('farmers_bank_account');
        });
    }

    public function down(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->dropColumn('default_tare_kg');
        });
    }
};
