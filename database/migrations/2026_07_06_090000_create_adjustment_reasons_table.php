<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjustment_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();   // spillage, shrinkage, theft, sampling, expiry, consumable, other
            $table->string('label', 60);
            $table->string('gl_account', 15)->nullable(); // expense account this reason debits on loss
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });

        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->foreignId('reason_id')->nullable()->after('location_id')
                ->constrained('adjustment_reasons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reason_id');
        });
        Schema::dropIfExists('adjustment_reasons');
    }
};
