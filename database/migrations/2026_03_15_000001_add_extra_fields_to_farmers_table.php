<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            $table->string('county')->nullable()->after('village');
            $table->string('sub_county')->nullable()->after('county');
            $table->string('location_area')->nullable()->after('sub_county');
            $table->string('sub_location')->nullable()->after('location_area');
            $table->string('nearest_town')->nullable()->after('sub_location');
            $table->string('spouse_name')->nullable()->after('nearest_town');
            $table->string('spouse_phone')->nullable()->after('spouse_name');
            $table->string('spouse_account_no')->nullable()->after('spouse_phone');
            $table->string('next_of_kin_name')->nullable()->after('spouse_account_no');
            $table->string('next_of_kin_account_no')->nullable()->after('next_of_kin_name');
            $table->string('member_no')->nullable()->after('next_of_kin_account_no');
            $table->string('related_member_nos')->nullable()->after('member_no');
            $table->string('director_name')->nullable()->after('related_member_nos');
        });
    }

    public function down(): void
    {
        Schema::table('farmers', function (Blueprint $table) {
            $table->dropColumn([
                'county', 'sub_county', 'location_area', 'sub_location', 'nearest_town',
                'spouse_name', 'spouse_phone', 'spouse_account_no',
                'next_of_kin_name', 'next_of_kin_account_no',
                'member_no', 'related_member_nos', 'director_name',
            ]);
        });
    }
};
