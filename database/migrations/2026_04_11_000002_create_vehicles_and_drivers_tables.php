<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate_no', 20)->unique();
            $table->string('make', 60);
            $table->string('model', 60);
            $table->unsignedSmallInteger('year')->nullable();
            $table->enum('type', ['truck', 'van', 'pickup', 'car', 'motorcycle', 'other'])->default('other');
            $table->string('color', 40)->nullable();
            $table->unsignedInteger('capacity')->nullable()->comment('Payload in kg or seats depending on type');
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('license_no', 40)->nullable();
            $table->string('license_class', 10)->nullable()->comment('A, B, C, BCE, etc.');
            $table->string('phone', 20)->nullable();
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->noActionOnDelete();
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('vehicles');
    }
};
