<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('casual_worker_pay_run_items', function (Blueprint $table) {
            // Tracks the user-entered manual portion so it survives payroll regeneration.
            // other_allowances = auto (from earning types) + manual_allowances
            // deductions        = auto (from earning types) + manual_deductions
            $table->decimal('manual_allowances', 15, 2)->default(0)->after('other_allowances');
            $table->decimal('manual_deductions',  15, 2)->default(0)->after('deductions');
        });
    }

    public function down(): void
    {
        Schema::table('casual_worker_pay_run_items', function (Blueprint $table) {
            $table->dropColumn(['manual_allowances', 'manual_deductions']);
        });
    }
};
