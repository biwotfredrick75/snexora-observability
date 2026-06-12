<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->boolean('auto_standard_cost')->default(true)->after('add_price_from_std_cost');
        });
    }

    public function down(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->dropColumn('auto_standard_cost');
        });
    }
};
