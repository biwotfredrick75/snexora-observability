<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_job_titles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('inactive')->default(false);
            $table->timestamps();

            $table->index('inactive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_job_titles');
    }
};
