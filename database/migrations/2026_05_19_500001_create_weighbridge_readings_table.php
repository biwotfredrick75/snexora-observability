<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighbridge_readings', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 30)->unique();
            $table->string('vehicle_no', 30)->nullable();
            $table->string('supplier_code', 30)->nullable();
            $table->string('supplier_name', 200)->nullable();
            $table->string('stock_id', 30)->nullable();
            $table->string('description', 200)->nullable();
            $table->string('unit', 20)->nullable()->default('Kgs');
            // Weight readings
            $table->decimal('gross_weight', 12, 4)->default(0);
            $table->decimal('tare_weight', 12, 4)->default(0);
            $table->decimal('net_weight', 12, 4)->storedAs('gross_weight - tare_weight');
            // Reading source: manual / scale / imported
            $table->enum('source', ['manual', 'scale', 'imported'])->default('manual');
            // Linked transaction
            $table->enum('trans_type', ['grn', 'work_order', 'stock_movement', 'sales', 'none'])->default('none');
            $table->string('trans_ref', 50)->nullable();
            $table->unsignedBigInteger('trans_id')->nullable();
            $table->boolean('applied')->default(false);
            $table->dateTime('weighed_at');
            $table->string('operator', 60)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['stock_id', 'weighed_at']);
            $table->index(['trans_type', 'trans_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weighbridge_readings');
    }
};
