<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A batch that finished posting (status=done) could still be re-initiated
 * for the exact same month/year/term/route — nothing stopped it, which is
 * how the same period ended up posted twice. `locked` is set to 1 the
 * moment a batch completes successfully and is checked by
 * FarmerPaymentProcessController::initiate() to refuse re-processing the
 * same scope. Preview screens (report/schedule) are read-only and never
 * check this — they stay available regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmer_payment_batches', function (Blueprint $table) {
            $table->boolean('locked')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('farmer_payment_batches', function (Blueprint $table) {
            $table->dropColumn('locked');
        });
    }
};
