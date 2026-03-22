<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_purchase_items', function (Blueprint $table) {
            $table->decimal('unit_price',  12, 4)->default(0)->after('quantity');
            $table->decimal('total_price', 12, 4)->default(0)->after('unit_price');
            $table->string('unique_key', 60)->nullable()->after('total_price');
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchase_items', function (Blueprint $table) {
            $table->dropColumn(['unit_price', 'total_price', 'unique_key']);
        });
    }
};
