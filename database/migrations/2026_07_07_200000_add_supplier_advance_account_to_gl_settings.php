<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->string('supplier_advance_account', 20)->nullable()->after('purchase_discount_account');
        });
    }

    public function down()
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->dropColumn('supplier_advance_account');
        });
    }
};
