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
        Schema::create('farmer_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('farmer_id');
            $table->string('assignment')->nullable();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('secondary_phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            $table->foreign('farmer_id')->references('id')->on('farmers')->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farmer_contacts');
    }
};
