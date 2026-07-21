<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            // 'app' = submitted by a grader-authenticated (Flutter) session and
            // auto-approved on arrival; 'web' = entered by office staff and still
            // requires manual approval. Determined server-side from the caller's
            // role, never trusted from client input.
            $table->string('source', 20)->default('web')->after('pricing_type');
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
