<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_sales_prices', function (Blueprint $table) {
            $table->decimal('grader_rate', 15, 4)->default(0)->after('price');
            $table->decimal('special_rate', 15, 4)->default(0)->after('grader_rate');
            $table->date('date_from')->nullable()->after('special_rate');
            $table->date('date_to')->nullable()->after('date_from');
        });

        // Drop the standalone grader_collection_rates table — rate now lives in item_sales_prices
        Schema::dropIfExists('grader_collection_rates');
    }

    public function down(): void
    {
        Schema::table('item_sales_prices', function (Blueprint $table) {
            $table->dropColumn(['grader_rate', 'special_rate', 'date_from', 'date_to']);
        });
    }
};
