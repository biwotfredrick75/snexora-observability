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
        Schema::create('tax_groups', function (Blueprint $table) {
            $table->id();
            $table->string('description', 100);
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });

        Schema::create('tax_group_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_group_id')->constrained('tax_groups')->noActionOnDelete();
            $table->foreignId('tax_type_id')->constrained('tax_types')->noActionOnDelete();
            $table->boolean('shipping')->default(false);
            $table->unique(['tax_group_id', 'tax_type_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_group_types');
        Schema::dropIfExists('tax_groups');
    }
};
