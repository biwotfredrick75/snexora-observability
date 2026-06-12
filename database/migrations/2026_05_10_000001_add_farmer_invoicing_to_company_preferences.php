<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->boolean('allow_farmer_invoicing')->default(false)->after('allow_negative_inventory');
            $table->decimal('farmer_invoice_limit_percent', 5, 2)->default(0)->after('allow_farmer_invoicing');
        });
    }

    public function down(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->dropColumn(['allow_farmer_invoicing', 'farmer_invoice_limit_percent']);
        });
    }
};
