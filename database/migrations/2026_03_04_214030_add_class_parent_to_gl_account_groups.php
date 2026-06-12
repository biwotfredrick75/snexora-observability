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
                $table->unsignedInteger('class_id')->nullable();
                $table->foreign('class_id')->references('id')->on('gl_account_classes')->noActionOnDelete();
            }
            if (!Schema::hasColumn('gl_account_groups', 'parent_id')) {
                $table->foreignId('parent_id')->nullable();
                $table->foreign('parent_id')->references('id')->on('gl_account_groups')->noActionOnDelete();
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
