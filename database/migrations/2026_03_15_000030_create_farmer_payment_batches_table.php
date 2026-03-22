<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('farmer_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('month');
            $table->smallInteger('year');
            $table->date('date_paid');
            $table->string('reference', 60)->nullable();      // GL reference / slip no
            $table->unsignedBigInteger('payment_term_id')->nullable();
            $table->unsignedBigInteger('route_id')->nullable();
            $table->integer('total_farmers')->default(0);
            $table->integer('processed_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->decimal('total_gross', 16, 4)->default(0);
            $table->decimal('total_deductions', 16, 4)->default(0);
            $table->decimal('total_net', 16, 4)->default(0);
            $table->enum('status', ['pending', 'processing', 'done', 'failed', 'cancelled'])->default('pending');
            $table->text('error_message')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['month', 'year']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_payment_batches');
    }
};
