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
        Schema::create('company_databases', function (Blueprint $table) {
            $table->id();
            $table->string('company', 100);
            $table->string('host', 100)->default('localhost');
            $table->string('port', 10)->nullable();
            $table->string('db_user', 100);
            $table->string('db_password', 255)->nullable();
            $table->string('db_name', 100);
            $table->string('collation', 50)->default('utf8mb4_unicode_ci');
            $table->string('table_prefix', 10)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_databases');
    }
};
