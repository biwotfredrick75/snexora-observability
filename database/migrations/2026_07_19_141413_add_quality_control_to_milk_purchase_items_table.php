<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_purchase_items', function (Blueprint $table) {
            $table->enum('smell_result', ['normal', 'abnormal'])->nullable()->after('captured_at');
            $table->enum('alcohol_test_result', ['negative', 'positive'])->nullable()->after('smell_result');
            $table->decimal('density', 6, 4)->nullable()->after('alcohol_test_result');
            $table->decimal('butterfat_percent', 5, 2)->nullable()->after('density');
            $table->enum('adulteration_result', ['negative', 'positive'])->nullable()->after('butterfat_percent');
            // Defaults to 'accepted' — quality fields are optional (older app
            // builds and manual web entry won't send them), and a batch with
            // no test data recorded shouldn't be treated as rejected.
            $table->enum('quality_status', ['accepted', 'rejected'])->default('accepted')->after('adulteration_result');
            $table->string('rejection_reason', 255)->nullable()->after('quality_status');
            $table->string('tested_by', 50)->nullable()->after('rejection_reason');
            $table->timestamp('tested_at')->nullable()->after('tested_by');

            $table->index('quality_status');
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchase_items', function (Blueprint $table) {
            $table->dropIndex(['quality_status']);
            $table->dropColumn([
                'smell_result', 'alcohol_test_result', 'density', 'butterfat_percent',
                'adulteration_result', 'quality_status', 'rejection_reason', 'tested_by', 'tested_at',
            ]);
        });
    }
};
