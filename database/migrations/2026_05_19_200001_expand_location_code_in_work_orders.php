<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('location_code', 200)->default('')->change();
            $table->string('output_location_code', 200)->default('')->change();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('location_code', 20)->default('')->change();
            $table->string('output_location_code', 20)->default('')->change();
        });
    }
};
