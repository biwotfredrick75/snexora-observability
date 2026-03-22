<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boms', function (Blueprint $table) {
            $table->id();
            $table->string('bom_no', 30)->unique();
            $table->string('product_code', 20);                        // items.stock_id
            $table->string('description', 200)->default('');
            $table->string('version', 20)->default('1.0');
            $table->decimal('standard_batch_qty', 14, 4)->default(1); // qty this BOM produces
            $table->string('batch_unit', 20)->default('');
            $table->boolean('is_active')->default(true);
            $table->string('created_by', 50)->default('');
            $table->timestamps();

            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boms');
    }
};
