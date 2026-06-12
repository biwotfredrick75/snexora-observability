<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_overhead', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->string('description', 200)->default('');
            $table->string('overhead_type', 20)->default('variable');   // variable | fixed
            $table->decimal('amount', 14, 4)->default(0);
            $table->date('date_posted');
            $table->string('created_by', 50)->default('');
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_overhead');
    }
};
