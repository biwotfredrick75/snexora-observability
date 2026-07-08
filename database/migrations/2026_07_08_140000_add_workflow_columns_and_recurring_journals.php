<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // status now: draft|approved|posted|rejected|voided (was posted|voided only)
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('approved_by', 60)->nullable()->after('status');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('posted_by', 60)->nullable()->after('approved_at');
            $table->timestamp('posted_at')->nullable()->after('posted_by');
            $table->text('rejected_reason')->nullable()->after('posted_at');
            $table->string('voided_by', 60)->nullable()->after('rejected_reason');
            $table->text('void_reason')->nullable()->after('voided_by');
        });

        Schema::create('recurring_journal_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_no', 30)->unique();
            $table->string('description', 255);
            $table->string('frequency', 20); // weekly|monthly|quarterly|yearly
            $table->date('start_date');
            $table->date('next_run_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('created_by', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('recurring_journal_template_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('recurring_journal_templates')->cascadeOnDelete();
            $table->string('account_code', 20);
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('narration', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_journal_template_lines');
        Schema::dropIfExists('recurring_journal_templates');
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at', 'posted_by', 'posted_at', 'rejected_reason', 'voided_by', 'void_reason']);
        });
    }
};
