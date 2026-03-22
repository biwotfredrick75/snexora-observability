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
        Schema::table('gl_account_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('gl_account_groups', 'class_id')) {
                $table->foreignId('class_id')->nullable()->constrained('gl_account_classes')->nullOnDelete();
            }
            if (!Schema::hasColumn('gl_account_groups', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->constrained('gl_account_groups')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('gl_account_groups', function (Blueprint $table) {
            $table->dropForeign(['class_id']);
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['class_id', 'parent_id']);
        });
    }
};
