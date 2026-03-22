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
        Schema::table('packaging_receives', function (Blueprint $table) {
            $table->string('from_location_id')->nullable()->after('receiving_date');
            $table->dropColumn(['customer_name', 'branch']);
        });
    }

    public function down(): void
    {
        Schema::table('packaging_receives', function (Blueprint $table) {
            $table->string('customer_name')->after('receiving_date');
            $table->string('branch')->nullable()->after('customer_name');
            $table->dropColumn('from_location_id');
        });
    }
};
