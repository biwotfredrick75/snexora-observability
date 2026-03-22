<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->string('approved_by')->nullable()->after('created_by');
            $table->text('reject_reason')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('milk_purchases', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'reject_reason']);
        });
    }
};
