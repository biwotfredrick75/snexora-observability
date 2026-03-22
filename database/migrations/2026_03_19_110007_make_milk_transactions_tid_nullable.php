<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_transactions', function (Blueprint $table) {
            $table->string('tid', 50)->nullable()->change();
            $table->unsignedBigInteger('purchase_id')->nullable()->after('id');
            $table->unsignedBigInteger('purchase_item_id')->nullable()->after('purchase_id');
            $table->string('unique_key', 60)->nullable()->after('purchase_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('milk_transactions', function (Blueprint $table) {
            $table->dropColumn(['purchase_id', 'purchase_item_id', 'unique_key']);
            $table->string('tid', 50)->nullable(false)->change();
        });
    }
};
