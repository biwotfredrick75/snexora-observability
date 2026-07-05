<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loading_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();
            $table->unsignedBigInteger('route_id');
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->date('order_date');
            $table->enum('status', ['draft', 'dispatched', 'delivered', 'cancelled'])->default('draft');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            // SQL Server has no explicit "ON DELETE RESTRICT" — omitting the action
            // gives the same restrict behavior (the default).
            $table->foreign('route_id')->references('id')->on('transport_routes');
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            $table->foreign('driver_id')->references('id')->on('drivers')->nullOnDelete();
        });

        Schema::create('loading_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loading_order_id');
            $table->string('description', 255);
            $table->decimal('quantity', 14, 2)->default(0);
            $table->string('unit', 20)->nullable();
            $table->timestamps();

            $table->foreign('loading_order_id')->references('id')->on('loading_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loading_order_items');
        Schema::dropIfExists('loading_orders');
    }
};
