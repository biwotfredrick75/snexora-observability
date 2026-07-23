<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('consumable_issues', function (Blueprint $table) {
            // Destination store / cost-centre the consumable is issued to
            // (e.g. a person, route, or department — modelled the same way
            // as from_location_id: a free-text inventory_locations.code,
            // informational only, no stock is received there).
            $table->string('to_location_id', 30)->nullable()->after('from_location_id');

            // Reason code → GL expense account, reusing the same
            // adjustment_reasons table InventoryAdjustment already uses,
            // so reasons and their GL mapping are managed in one place.
            // gl_account is kept (now nullable) only as a legacy fallback
            // for rows created before this column existed.
            $table->foreignId('reason_id')->nullable()->after('to_location_id')
                ->constrained('adjustment_reasons')->nullOnDelete();

            // Finance Approval stage — sits between "pending" (submitted,
            // awaiting finance) and "approved" (store has physically
            // issued the stock and GL has been posted). See status comment
            // update below.
            $table->string('finance_approved_by', 30)->nullable()->after('created_by');
            $table->timestamp('finance_approved_at')->nullable()->after('finance_approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consumable_issues', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reason_id');
            $table->dropColumn(['to_location_id', 'finance_approved_by', 'finance_approved_at']);
        });
    }
};
