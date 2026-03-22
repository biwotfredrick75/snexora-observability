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
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->string('debtors_gl_code', 20)->nullable()->after('currency');
            $table->string('discount_gl_code', 20)->nullable()->after('debtors_gl_code');
        });
    }

    public function down(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->dropColumn(['debtors_gl_code', 'discount_gl_code']);
        });
    }
};
