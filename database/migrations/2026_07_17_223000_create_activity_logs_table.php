<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General user-activity audit trail (who did what, when) — distinct from
 * `audit_trails`, which is a FrontAccounting-style GL-posting log keyed by
 * transaction type/number, and distinct from the "Auto Audit" feature
 * (Audit\AuditController), which is a compliance/data-integrity scanner.
 * Neither of those tracks logins, user/role management, or void actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            // Denormalized snapshot so the log stays readable if the user
            // record is later deleted/deactivated.
            $table->string('user_name', 100)->nullable();
            // e.g. 'login', 'login_failed', 'logout', 'created', 'updated', 'deleted', 'voided'
            $table->string('action', 40);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->string('description', 255);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
