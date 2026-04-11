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
        // One import session per uploaded file
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 20);
            $table->date('statement_from')->nullable();
            $table->date('statement_to')->nullable();
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->decimal('closing_balance', 18, 4)->default(0);
            $table->string('filename', 255)->nullable();
            $table->string('status', 20)->default('imported'); // imported | reconciled
            $table->string('imported_by', 60)->nullable();
            $table->timestamps();
        });

        // Individual statement lines
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained('bank_statement_imports')->cascadeOnDelete();
            $table->date('line_date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 120)->nullable();
            $table->decimal('debit', 18, 4)->default(0);    // money out
            $table->decimal('credit', 18, 4)->default(0);   // money in
            $table->decimal('balance', 18, 4)->nullable();
            $table->unsignedBigInteger('matched_gld_id')->nullable();
            $table->string('match_type', 20)->nullable();    // auto | manual
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statement_imports');
    }
};
