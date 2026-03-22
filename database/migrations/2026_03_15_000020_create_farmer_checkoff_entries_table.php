<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('farmer_checkoff_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('farmer_id');
            $table->tinyInteger('month');          // 1-12
            $table->smallInteger('year');
            $table->unsignedBigInteger('service_id')->nullable(); // FK → checkoff_services
            $table->string('service_name', 100)->nullable();      // override / ad-hoc label
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->foreign('farmer_id')->references('id')->on('farmers');
            $table->foreign('service_id')->references('id')->on('checkoff_services')->nullOnDelete();
            $table->index(['farmer_id', 'month', 'year']);
            $table->index(['month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_checkoff_entries');
    }
};
