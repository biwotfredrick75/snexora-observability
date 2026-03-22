<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_name', 100);
            $table->decimal('factor', 10, 4)->default(1);
            $table->boolean('tax_incl')->default(false);
            $table->timestamps();
        });

        Schema::create('sales_areas', function (Blueprint $table) {
            $table->id();
            $table->string('area_name', 100);
            $table->timestamps();
        });

        Schema::create('sales_persons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('telephone', 50)->nullable();
            $table->string('fax', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->decimal('provision_pct', 8, 4)->default(0);
            $table->decimal('turnover_break_pt', 15, 4)->default(0);
            $table->decimal('provision2_pct', 8, 4)->default(0);
            $table->string('username', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('sales_groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_name', 100);
            $table->timestamps();
        });

        Schema::create('credit_note_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('reason_code', 20);
            $table->string('reason_name', 150);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('credit_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('description', 150);
            $table->boolean('disallow_invoices')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_statuses');
        Schema::dropIfExists('credit_note_reasons');
        Schema::dropIfExists('sales_groups');
        Schema::dropIfExists('sales_persons');
        Schema::dropIfExists('sales_areas');
        Schema::dropIfExists('sales_types');
    }
};
