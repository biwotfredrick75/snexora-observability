<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_quality_settings', function (Blueprint $table) {
            // Controls which tests the collection app even asks for — separate
            // from the reject_on_* flags, which control whether a FAILED test
            // causes rejection. A company can enable a test purely for
            // record-keeping without auto-rejecting on it, or disable a test
            // entirely so it never appears in the app.
            $table->boolean('enable_smell_test')->default(true)->after('reject_on_abnormal_smell');
            $table->boolean('enable_alcohol_test')->default(true)->after('enable_smell_test');
            $table->boolean('enable_density_test')->default(true)->after('enable_alcohol_test');
            $table->boolean('enable_butterfat_test')->default(true)->after('enable_density_test');
            $table->boolean('enable_adulteration_test')->default(true)->after('enable_butterfat_test');
        });
    }

    public function down(): void
    {
        Schema::table('milk_quality_settings', function (Blueprint $table) {
            $table->dropColumn([
                'enable_smell_test', 'enable_alcohol_test', 'enable_density_test',
                'enable_butterfat_test', 'enable_adulteration_test',
            ]);
        });
    }
};
