<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_labour', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->string('operator_name', 100)->default('');
            $table->string('role', 100)->default('');
            $table->decimal('rate_per_hour', 12, 4)->default(0);
            $table->decimal('hours_worked', 10, 2)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);          // hours × rate
            $table->date('work_date');
            $table->string('created_by', 50)->default('');
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_labour');
    }
};
