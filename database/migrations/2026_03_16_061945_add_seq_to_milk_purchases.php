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
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('seq')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->dropColumn('seq');
        });
    }
};
