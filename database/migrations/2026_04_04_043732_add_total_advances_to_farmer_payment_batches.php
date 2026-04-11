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
        Schema::table('farmer_payment_batches', function (Blueprint $table) {
            $table->decimal('total_advances', 15, 2)->default(0)->after('total_gross');
        });
    }

    public function down(): void
    {
        Schema::table('farmer_payment_batches', function (Blueprint $table) {
            $table->dropColumn('total_advances');
        });
    }
};
