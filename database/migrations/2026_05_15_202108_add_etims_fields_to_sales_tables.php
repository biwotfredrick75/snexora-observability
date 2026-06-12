<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = function (Blueprint $table) {
            $table->string('etims_status', 20)->nullable()->after('status'); // pending|signed|stamped|failed
            $table->bigInteger('etims_invc_no')->nullable()->after('etims_status');
            $table->bigInteger('etims_rpt_no')->nullable()->after('etims_invc_no');
            $table->text('etims_intr_data')->nullable()->after('etims_rpt_no');
            $table->text('etims_rct_sign')->nullable()->after('etims_intr_data');
            $table->string('etims_rcpt_date', 30)->nullable()->after('etims_rct_sign');
            $table->string('etims_mrc_no', 50)->nullable()->after('etims_rcpt_date');
            $table->string('etims_qr_path', 255)->nullable()->after('etims_mrc_no');
            $table->timestamp('etims_stamped_at')->nullable()->after('etims_qr_path');
            $table->text('etims_error')->nullable()->after('etims_stamped_at');
        };

        Schema::table('sales_invoices', $columns);
        Schema::table('credit_notes', $columns);
    }

    public function down(): void
    {
        $drop = ['etims_status', 'etims_invc_no', 'etims_rpt_no', 'etims_intr_data',
                 'etims_rct_sign', 'etims_rcpt_date', 'etims_mrc_no', 'etims_qr_path',
                 'etims_stamped_at', 'etims_error'];

        Schema::table('sales_invoices', fn(Blueprint $t) => $t->dropColumn($drop));
        Schema::table('credit_notes',   fn(Blueprint $t) => $t->dropColumn($drop));
    }
};
