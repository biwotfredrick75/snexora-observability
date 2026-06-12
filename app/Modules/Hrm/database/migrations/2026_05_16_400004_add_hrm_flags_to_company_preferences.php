<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('company_preferences', 'hrm_enabled')) {
                $table->boolean('hrm_enabled')->default(true);
            }
            if (! Schema::hasColumn('company_preferences', 'payroll_enabled')) {
                $table->boolean('payroll_enabled')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->dropColumn(['hrm_enabled', 'payroll_enabled']);
        });
    }
};
