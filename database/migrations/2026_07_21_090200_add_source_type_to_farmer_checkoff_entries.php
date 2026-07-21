<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmer_checkoff_entries', function (Blueprint $table) {
            // Traceability for entries posted programmatically (e.g. by the
            // SACCO module's checkoff-posting step) — same shape as
            // grader_deductions' source_type/source_ref, lets a caller find
            // and guard against re-posting the same underlying event.
            $table->string('source_type', 30)->nullable()->after('note');
            $table->string('source_ref', 30)->nullable()->after('source_type');
            $table->index(['source_type', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::table('farmer_checkoff_entries', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_ref']);
            $table->dropColumn(['source_type', 'source_ref']);
        });
    }
};
