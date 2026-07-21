<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── SACCO Members ────────────────────────────────────────────────────
        // A member is always the same person as an existing Farmer — this
        // links to, not duplicates, the farmers table.
        if (! Schema::hasTable('sacco_members')) {
            Schema::create('sacco_members', function (Blueprint $table) {
                $table->id();
                $table->string('membership_no', 20)->unique();
                $table->unsignedBigInteger('farmer_id')->unique();
                $table->date('join_date');
                $table->string('status', 20)->default('active'); // active, inactive, exited
                $table->string('created_by', 60)->nullable();
                $table->timestamps();
                $table->foreign('farmer_id')->references('id')->on('farmers');
                $table->index('status');
            });
        }

        // ── SACCO Accounts (savings + shares) ───────────────────────────────
        // Exactly two rows per member, auto-created at registration — one
        // savings account, one shares account.
        if (! Schema::hasTable('sacco_accounts')) {
            Schema::create('sacco_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('member_id');
                $table->string('account_type', 20); // savings, shares
                $table->string('account_no', 30)->unique();
                $table->decimal('balance', 15, 2)->default(0);
                $table->string('status', 20)->default('active');
                $table->timestamps();
                $table->foreign('member_id')->references('id')->on('sacco_members');
                $table->unique(['member_id', 'account_type']);
            });
        }

        // ── SACCO Transactions (savings/shares ledger) ──────────────────────
        // Loan disbursement/repayment events live in the loan tables below,
        // not here — a member's full activity view is assembled from both.
        if (! Schema::hasTable('sacco_transactions')) {
            Schema::create('sacco_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->string('type', 20); // deposit, withdrawal, dividend, interest, share_purchase
                $table->decimal('amount', 15, 2);
                $table->string('reference', 30)->nullable();
                $table->date('transaction_date');
                $table->text('narration')->nullable();
                $table->string('source', 20)->default('cash'); // cash, bank, manual
                $table->string('created_by', 60)->nullable();
                $table->timestamps();
                $table->foreign('account_id')->references('id')->on('sacco_accounts');
                $table->index(['account_id', 'transaction_date']);
            });
        }

        // ── SACCO Loan Products ──────────────────────────────────────────────
        if (! Schema::hasTable('sacco_loan_products')) {
            Schema::create('sacco_loan_products', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->decimal('interest_rate_pct', 5, 2);
                $table->unsignedInteger('max_term_months');
                $table->decimal('max_savings_multiplier', 5, 2)->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();
            });
        }

        // ── SACCO Loans ───────────────────────────────────────────────────────
        if (! Schema::hasTable('sacco_loans')) {
            Schema::create('sacco_loans', function (Blueprint $table) {
                $table->id();
                $table->string('loan_no', 30)->unique();
                $table->unsignedBigInteger('member_id');
                $table->unsignedBigInteger('product_id');
                $table->decimal('principal_amount', 15, 2);
                $table->decimal('interest_rate_pct', 5, 2); // snapshot from product at disbursement
                $table->unsignedInteger('term_months');
                $table->date('application_date');
                $table->date('disbursement_date')->nullable();
                $table->string('status', 20)->default('pending'); // pending, approved, disbursed, settled, rejected, defaulted
                $table->decimal('outstanding_balance', 15, 2)->default(0);
                $table->string('approved_by', 60)->nullable();
                $table->string('disbursed_by', 60)->nullable();
                $table->string('created_by', 60)->nullable();
                $table->timestamps();
                $table->foreign('member_id')->references('id')->on('sacco_members');
                $table->foreign('product_id')->references('id')->on('sacco_loan_products');
                $table->index(['member_id', 'status']);
            });
        }

        // ── SACCO Loan Guarantors ────────────────────────────────────────────
        if (! Schema::hasTable('sacco_loan_guarantors')) {
            Schema::create('sacco_loan_guarantors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loan_id');
                $table->unsignedBigInteger('member_id'); // the guarantor
                $table->decimal('guaranteed_amount', 15, 2);
                $table->string('status', 20)->default('active'); // active, released
                $table->timestamps();
                $table->foreign('loan_id')->references('id')->on('sacco_loans')->cascadeOnDelete();
                $table->foreign('member_id')->references('id')->on('sacco_members');
            });
        }

        // ── SACCO Loan Repayment Schedule ────────────────────────────────────
        // Generated in full at disbursement (reducing-balance amortization).
        // This IS the repayment queue — no separate "deduction" table needed.
        if (! Schema::hasTable('sacco_loan_repayment_schedule')) {
            Schema::create('sacco_loan_repayment_schedule', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('loan_id');
                $table->unsignedInteger('installment_no');
                $table->date('due_date');
                $table->decimal('principal_due', 15, 2);
                $table->decimal('interest_due', 15, 2);
                $table->decimal('total_due', 15, 2);
                $table->decimal('amount_paid', 15, 2)->default(0);
                $table->string('status', 20)->default('pending'); // pending, paid
                $table->string('paid_via', 20)->nullable(); // milk_checkoff, cash, bank
                $table->date('paid_date')->nullable();
                // Set once postCheckoffForPeriod() has written the matching
                // farmer_checkoff_entries row — guards against double-posting.
                $table->timestamp('checkoff_posted_at')->nullable();
                $table->timestamps();
                $table->foreign('loan_id')->references('id')->on('sacco_loans')->cascadeOnDelete();
                $table->index(['loan_id', 'status']);
                $table->index(['due_date', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_loan_repayment_schedule');
        Schema::dropIfExists('sacco_loan_guarantors');
        Schema::dropIfExists('sacco_loans');
        Schema::dropIfExists('sacco_loan_products');
        Schema::dropIfExists('sacco_transactions');
        Schema::dropIfExists('sacco_accounts');
        Schema::dropIfExists('sacco_members');
    }
};
