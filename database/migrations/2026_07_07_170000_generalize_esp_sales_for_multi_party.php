<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esp_farmer_sales', function (Blueprint $table) {
            $table->string('party_type', 20)->default('farmer')->after('esp_id');
            $table->unsignedBigInteger('party_id')->nullable()->after('party_type');
            $table->index(['party_type', 'party_id', 'status']);
        });

        // farmer_id must become nullable (employee/transporter sales won't have one).
        // doctrine/dbal isn't installed, so Blueprint::change() isn't available —
        // alter directly per driver (local dev runs mysql, deployed backend runs sqlsrv).
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('ALTER TABLE esp_farmer_sales ALTER COLUMN farmer_id BIGINT NULL');
        } else {
            DB::statement('ALTER TABLE esp_farmer_sales MODIFY farmer_id BIGINT UNSIGNED NULL');
        }

        DB::table('esp_farmer_sales')->whereNull('party_id')->update([
            'party_id'   => DB::raw('farmer_id'),
            'party_type' => 'farmer',
        ]);

        if (! Schema::hasTable('esp_sale_adjustments')) {
            Schema::create('esp_sale_adjustments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sale_id');
                $table->decimal('delta_amount', 15, 2);
                $table->string('reason', 255);
                $table->string('created_by', 60)->nullable();
                $table->timestamps();
                $table->foreign('sale_id')->references('id')->on('esp_farmer_sales')->cascadeOnDelete();
                $table->index('sale_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esp_sale_adjustments');

        Schema::table('esp_farmer_sales', function (Blueprint $table) {
            $table->dropIndex(['party_type', 'party_id', 'status']);
            $table->dropColumn(['party_type', 'party_id']);
        });
    }
};
