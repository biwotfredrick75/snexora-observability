<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * farmer_direct_invoices had no way to record that an invoice had already
 * been recovered via a farmer payment batch — status only ever moved
 * draft -> placed -> cancelled, so a settled invoice still looked exactly
 * like an outstanding one. Mirrors the party_deducted/party_deducted_at/
 * party_deducted_ref pattern already used on esp_farmer_sales, rather than
 * overloading `status` (which several places already filter on directly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmer_direct_invoices', function (Blueprint $table) {
            $table->boolean('settled')->default(false)->after('status');
            $table->date('settled_at')->nullable()->after('settled');
            $table->string('settled_ref', 60)->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('farmer_direct_invoices', function (Blueprint $table) {
            $table->dropColumn(['settled', 'settled_at', 'settled_ref']);
        });
    }
};
