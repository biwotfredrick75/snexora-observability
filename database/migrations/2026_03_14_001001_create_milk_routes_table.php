<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_routes', function (Blueprint $table) {
            $table->id();
            $table->string('route_code');
            $table->string('route_name');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->json('merged_route_ids')->nullable();
            $table->json('sessions')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_routes');
    }
};
