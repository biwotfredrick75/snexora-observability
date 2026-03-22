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
        Schema::create('item_tax_types', function (Blueprint $table) {
            $table->id();
            $table->string('description', 100);
            $table->boolean('fully_exempt')->default(false);
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });

        Schema::create('item_tax_type_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_tax_type_id')->constrained('item_tax_types')->cascadeOnDelete();
            $table->foreignId('tax_type_id')->constrained('tax_types')->cascadeOnDelete();
            $table->unique(['item_tax_type_id', 'tax_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_tax_type_exemptions');
        Schema::dropIfExists('item_tax_types');
    }
};
