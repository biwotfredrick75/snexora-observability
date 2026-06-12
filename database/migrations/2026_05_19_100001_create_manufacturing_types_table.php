<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('manufacturing_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->string('icon', 10)->default('⚙️');
            $table->string('color', 20)->default('blue');
            $table->json('process_stages')->nullable();  // [{code, name, description, order}]
            $table->json('qa_fields')->nullable();       // [{key, label, type, unit, required}]
            $table->json('output_fields')->nullable();   // [{key, label, type, unit}]
            $table->boolean('is_active')->default(true);
            $table->boolean('is_builtin')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('mfg_type_id')->nullable()->after('id');
            $table->json('mfg_attributes')->nullable()->after('notes');
            $table->foreign('mfg_type_id')->references('id')->on('manufacturing_types')->noActionOnDelete();
        });

        Schema::table('production_plans', function (Blueprint $table) {
            $table->unsignedBigInteger('mfg_type_id')->nullable()->after('id');
            $table->foreign('mfg_type_id')->references('id')->on('manufacturing_types')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_plans', function (Blueprint $table) {
            $table->dropForeign(['mfg_type_id']);
            $table->dropColumn('mfg_type_id');
        });
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropForeign(['mfg_type_id']);
            $table->dropColumn(['mfg_type_id', 'mfg_attributes']);
        });
        Schema::dropIfExists('manufacturing_types');
    }
};
