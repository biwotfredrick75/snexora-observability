<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('grader_id')->nullable()->after('route_id');
            $table->foreign('grader_id')->references('id')->on('inventory_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->dropForeign(['grader_id']);
            $table->dropColumn('grader_id');
        });
    }
};
