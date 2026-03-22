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
        Schema::create('gl_account_classes', function (Blueprint $table) {
            // User-defined integer ID (not auto-increment)
            $table->unsignedInteger('id');
            $table->primary('id');
            $table->string('name', 100);
            $table->enum('class_type', ['Assets', 'Liabilities', 'Equity', 'Income', 'Expense']);
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_account_classes');
    }
};
