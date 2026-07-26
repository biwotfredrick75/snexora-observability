<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->string('sacco_org_slug', 100)->nullable()->after('etims_gateway_url');
            $table->string('sacco_api_key', 255)->nullable()->after('sacco_org_slug');
            $table->string('sacco_gateway_url', 255)->nullable()->default('http://localhost:8090')->after('sacco_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->dropColumn(['sacco_org_slug', 'sacco_api_key', 'sacco_gateway_url']);
        });
    }
};
