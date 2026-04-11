<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blockchain_anchors', function (Blueprint $table) {
            $table->id();

            // What was anchored
            $table->string('trans_type', 60)->index(); // final_payment | invoice_settlement | advance_payment
            $table->string('trans_no',  80)->index();  // human-readable document ref (e.g. FPY-202603-...)
            $table->unsignedBigInteger('trans_id')->nullable(); // FK to the originating record (batch_id, payment_id, etc.)

            // Hash
            $table->string('doc_hash', 64);             // SHA-256 hex of the canonical JSON payload
            $table->string('tx_hash',  200)->nullable(); // on-chain tx hash (null while pending)

            // Chain info
            $table->string('network',   40)->nullable(); // ethereum | polygon | fabric
            $table->string('status',    20)->default('pending'); // pending | confirmed | failed
            $table->string('explorer_url', 300)->nullable();
            $table->text('error_message')->nullable();

            // Timestamps
            $table->timestamp('anchored_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blockchain_anchors');
    }
};
