<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        
        Schema::table('sales_kit_items', function (Blueprint $table) {
            $table->string('component_id', 20)->change();
        });

            }

    public function down(): void
    {
        
        Schema::table('sales_kit_items', function (Blueprint $table) {
            $table->unsignedBigInteger('component_id')->change();
        });

            }
};
