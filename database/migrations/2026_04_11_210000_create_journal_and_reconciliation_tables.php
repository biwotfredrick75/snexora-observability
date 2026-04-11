<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Journal Entry header (one row per journal)
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('journal_no', 30)->unique();
            $table->date('journal_date');
            $table->date('document_date')->nullable();
            $table->date('event_date')->nullable();
            $table->string('currency', 10)->default('KES');
            $table->decimal('exchange_rate', 12, 6)->default(1);
            $table->string('reference', 80)->nullable();
            $table->boolean('include_in_inception')->default(false);
            $table->text('memo')->nullable();
            $table->string('status', 20)->default('posted'); // posted|voided
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->index('journal_date');
        });

        // Journal Entry lines
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->string('account_code', 20);
            $table->string('counterparty', 100)->nullable();
            $table->unsignedBigInteger('dimension_id')->nullable();
            $table->unsignedBigInteger('dimension2_id')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('narration', 255)->nullable();
        });

        // Bank reconciliation — one session per account+statement_date
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 20);
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2)->default(0);
            $table->string('status', 20)->default('open'); // open|reconciled
            $table->string('reconciled_by', 60)->nullable();
            $table->date('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['account_code', 'statement_date']);
            $table->index('account_code');
        });

        // Which GL lines have been ticked in a reconciliation
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->unsignedBigInteger('gld_transaction_id');
            $table->index('gld_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_items');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }
};
