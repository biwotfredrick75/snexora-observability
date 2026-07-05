<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_module_configs')) {
            return;
        }
        Schema::create('app_module_configs', function (Blueprint $table) {
            $table->string('module_id', 50)->primary();
            $table->string('label', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_module_configs');
    }
};
