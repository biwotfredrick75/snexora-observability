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
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->string('etims_bhf_id', 10)->nullable()->default('00')->after('kra_pin');
            $table->string('etims_dvc_srl_no', 100)->nullable()->after('etims_bhf_id');
            $table->string('etims_env', 10)->nullable()->default('test')->after('etims_dvc_srl_no');
            $table->string('etims_api_key', 255)->nullable()->after('etims_env');
            $table->string('etims_gateway_url', 255)->nullable()->default('http://localhost:8080')->after('etims_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('company_preferences', function (Blueprint $table) {
            $table->dropColumn(['etims_bhf_id', 'etims_dvc_srl_no', 'etims_env', 'etims_api_key', 'etims_gateway_url']);
        });
    }
};
