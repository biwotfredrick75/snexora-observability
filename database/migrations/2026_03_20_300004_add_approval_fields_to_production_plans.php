<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->string('supervisor_approved_by', 100)->nullable()->after('approved_by');
            $table->timestamp('supervisor_approved_at')->nullable()->after('approved_at');
            $table->text('approve_comments')->nullable()->after('memo');
            $table->text('reject_comments')->nullable()->after('approve_comments');
            $table->string('rejected_by', 100)->nullable()->after('reject_comments');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->dropColumn([
                'supervisor_approved_by', 'supervisor_approved_at',
                'approve_comments', 'reject_comments', 'rejected_by', 'rejected_at',
            ]);
        });
    }
};
