<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            // Purchase Order approvals
            $table->boolean('po_requires_hod_approval')->default(false)->after('allow_negative_inventory');
            $table->boolean('po_requires_finance_approval')->default(false)->after('po_requires_hod_approval');
            $table->boolean('po_requires_ceo_approval')->default(false)->after('po_requires_finance_approval');
            // Purchase Requisition approvals
            $table->boolean('pr_requires_hod_approval')->default(false)->after('po_requires_ceo_approval');
            $table->boolean('pr_requires_finance_approval')->default(false)->after('pr_requires_hod_approval');
            $table->boolean('pr_requires_ceo_approval')->default(false)->after('pr_requires_finance_approval');
        });
    }

    public function down(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'po_requires_hod_approval',
                'po_requires_finance_approval',
                'po_requires_ceo_approval',
                'pr_requires_hod_approval',
                'pr_requires_finance_approval',
                'pr_requires_ceo_approval',
            ]);
        });
    }
};
