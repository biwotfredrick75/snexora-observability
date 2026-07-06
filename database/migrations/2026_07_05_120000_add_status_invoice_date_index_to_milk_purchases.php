<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->index(['status', 'invoice_date'], 'milk_purchases_status_invoice_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->dropIndex('milk_purchases_status_invoice_date_idx');
        });
    }
};
