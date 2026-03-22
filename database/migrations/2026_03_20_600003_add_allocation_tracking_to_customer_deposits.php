<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_deposits', function (Blueprint $table) {
            $table->decimal('allocated_amount', 15, 2)->default(0)->after('deposit_amount');
            $table->decimal('unallocated_amount', 15, 2)->default(0)->after('allocated_amount');
        });

        // Backfill existing rows: unallocated = deposit_amount
        \Illuminate\Support\Facades\DB::statement(
            'UPDATE customer_deposits SET unallocated_amount = deposit_amount WHERE status = \'posted\''
        );
    }

    public function down(): void
    {
        Schema::table('customer_deposits', function (Blueprint $table) {
            $table->dropColumn(['allocated_amount', 'unallocated_amount']);
        });
    }
};
