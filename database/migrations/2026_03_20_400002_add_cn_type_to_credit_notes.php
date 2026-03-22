<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            // return=goods back to stock, discount=price adjustment, write_off=bad debt
            $table->enum('cn_type', ['return', 'discount', 'write_off'])->default('return')->after('inv_id');
            // For write_off type the amount can be set directly (no line items required)
            $table->decimal('write_off_amount', 15, 2)->default(0)->after('amount_total');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn(['cn_type', 'write_off_amount']);
        });
    }
};
