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
        // Columns were partially added in a failed run — only add FKs
        Schema::table('gl_account_groups', function (Blueprint $table) {
            $table->foreign('class_id')->references('id')->on('gl_account_classes')->nullOnDelete();
            $table->foreign('parent_id')->references('id')->on('gl_account_groups')->nullOnDelete();
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
