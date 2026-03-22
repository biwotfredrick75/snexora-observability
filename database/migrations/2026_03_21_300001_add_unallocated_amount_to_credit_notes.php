<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->decimal('unallocated_amount', 15, 2)->default(0)->after('amount_total');
            $table->decimal('allocated_amount', 15, 2)->default(0)->after('unallocated_amount');
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn(['unallocated_amount', 'allocated_amount']);
        });
    }
};
