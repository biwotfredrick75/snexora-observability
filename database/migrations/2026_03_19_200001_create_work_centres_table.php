<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_centres', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('rate_per_hour', 12, 4)->default(0);       // labour/machine rate
            $table->decimal('overhead_rate', 12, 4)->default(0);       // overhead per machine hour
            $table->string('gl_account', 20)->nullable();              // cost centre GL account
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_centres');
    }
};
