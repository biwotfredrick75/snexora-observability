<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->decimal('total_amount', 14, 4)->default(0)->after('total_qty');
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->dropColumn('total_amount');
        });
    }
};
