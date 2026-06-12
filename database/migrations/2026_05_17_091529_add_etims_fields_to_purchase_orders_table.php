<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('etims_status', 20)->nullable()->after('status');
            $table->integer('etims_invc_no')->nullable()->after('etims_status');
            $table->string('etims_rpt_no', 50)->nullable()->after('etims_invc_no');
            $table->timestamp('etims_stamped_at')->nullable()->after('etims_rpt_no');
            $table->text('etims_error')->nullable()->after('etims_stamped_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['etims_status', 'etims_invc_no', 'etims_rpt_no', 'etims_stamped_at', 'etims_error']);
        });
    }
};
