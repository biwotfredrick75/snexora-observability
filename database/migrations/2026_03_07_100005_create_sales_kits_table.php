<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_kits', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });

        Schema::create('sales_kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kit_id')->constrained('sales_kits')->cascadeOnDelete();
            $table->string('alias_code', 50)->nullable();
            $table->unsignedBigInteger('component_id');
            $table->decimal('quantity', 18, 6)->default(1);
            $table->string('uom', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_kit_items');
        Schema::dropIfExists('sales_kits');
    }
};
