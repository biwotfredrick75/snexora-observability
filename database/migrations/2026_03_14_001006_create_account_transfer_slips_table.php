<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_transfer_slips', function (Blueprint $table) {
            $table->id();
            $table->string('slip_no')->unique();
            $table->date('transfer_date');
            $table->string('reason')->nullable();
            $table->string('relationship')->nullable();
            $table->string('death_cert_no')->nullable();
            $table->string('old_supplier_id')->nullable();
            $table->string('new_owner_name');
            $table->string('new_owner_id_no')->nullable();
            $table->date('dob')->nullable();
            $table->string('gender')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('nok_name')->nullable();
            $table->string('nok_id_no')->nullable();
            $table->string('nok_phone')->nullable();
            $table->string('nok_relationship')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transfer_slips');
    }
};
