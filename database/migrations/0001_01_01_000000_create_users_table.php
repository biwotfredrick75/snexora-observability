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
        Schema::create('users', function (Blueprint $table) {
            // Primary Key
            $table->smallIncrements('id');
            
            // Authentication
            $table->string('user_id', 60)->unique()->index();
            $table->string('password', 100);
            $table->datetime('password_last_changed')->nullable();
            $table->boolean('password_expiry_notified')->nullable()->default(0);
            
            // User Information
            $table->string('real_name', 100);
            $table->string('phone', 30);
            $table->string('email', 100)->nullable()->unique();
            $table->string('pin', 100);
            
            // Role & Permissions
            $table->unsignedInteger('role_id')->default(1)->index();
            
            // Localization & Preferences
            $table->string('language', 20)->nullable();
            $table->tinyInteger('date_format')->default(0);
            $table->tinyInteger('date_sep')->default(0);
            $table->tinyInteger('tho_sep')->default(0);
            $table->tinyInteger('dec_sep')->default(0);
            
            // UI Preferences
            $table->string('theme', 20)->default('default');
            $table->string('page_size', 20)->default('A4');
            $table->smallInteger('prices_dec')->default(2);
            $table->smallInteger('qty_dec')->default(2);
            $table->smallInteger('rates_dec')->default(4);
            $table->smallInteger('percent_dec')->default(1);
            
            // Display Options
            $table->boolean('show_gl')->default(1);
            $table->boolean('show_codes')->default(0);
            $table->boolean('show_hints')->default(0);
            $table->boolean('graphic_links')->nullable()->default(1);
            $table->boolean('rep_popup')->nullable()->default(1);
            $table->boolean('sticky_doc_date')->nullable()->default(0);
            
            // Print & Report Settings
            $table->string('print_profile', 30);
            $table->tinyInteger('def_print_destination')->default(0);
            $table->tinyInteger('def_print_orientation')->default(0);
            $table->smallInteger('pos')->nullable()->default(1);
            
            // Application Settings
            $table->string('startup_tab', 20);
            $table->smallInteger('transaction_days')->default(30);
            $table->smallInteger('save_report_selections')->default(0);
            $table->boolean('use_date_picker')->default(1);
            $table->string('default_store', 50);
            
            // Query & Navigation
            $table->unsignedTinyInteger('query_size')->default(10);
            
            // Account Status
            $table->boolean('inactive')->default(0)->index();
            $table->datetime('last_visit_date')->nullable();
            
            // Timestamps
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
