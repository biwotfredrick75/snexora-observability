<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('grn_item_id')->nullable()->after('po_id');
        });
    }
    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('grn_item_id');
        });
    }
};
