<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('farmer_payments', function (Blueprint $table) {
            // Links back to gld_transactions (type + trans_no) so the GL
            // voucher for this payment can be looked up later, not just
            // right after posting.
            $table->unsignedInteger('gl_trans_no')->nullable()->after('created_by');
            $table->unsignedSmallInteger('gl_type')->nullable()->after('gl_trans_no');
        });
    }

    public function down(): void
    {
        Schema::table('farmer_payments', function (Blueprint $table) {
            $table->dropColumn(['gl_trans_no', 'gl_type']);
        });
    }
};
