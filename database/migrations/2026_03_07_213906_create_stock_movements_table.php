<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->increments('trans_id');
            $table->integer('trans_no')->default(0);
            $table->char('stock_id',20)->default('');
            $table->smallInteger('type')->default(0);
            $table->char('loc_code',20)->default('');
            $table->string('route_id',200)->default('');
            $table->string('shift',200)->default('');
            $table->date('tran_date')->nullable();
            $table->date('date_moved')->nullable();
            $table->double('price')->default(0);
            $table->char('reference',40)->default('');
            $table->double('qty')->default(1);
            $table->double('standard_cost')->default(0);
            $table->string('tid',200)->default('');
            $table->text('comments')->nullable();
            $table->string('user_name',100)->default('');
            $table->smallInteger('approved')->default(1);
            $table->string('ref_no',50)->default('REF');
            $table->string('gate_pass_no',50)->default('');
            $table->smallInteger('reconciled')->default(0);
            $table->string('batch_no',50)->default('');
            $table->string('tidd',50)->default('');
            $table->timestamp('trans_time')->useCurrent();
            $table->string('vehicle',50)->default('');
            $table->integer('work_plan_id')->nullable();
            $table->integer('product_type')->default(0);
            $table->integer('produce_qty')->default(0);
            $table->boolean('sc_updated')->default(0);
            $table->float('grater_rate',10,2)->default(0.00);
            $table->string('price_list_id',100)->default('0');
            $table->string('unique_key',200)->nullable();
            $table->string('loc_code_from',100)->nullable();
            $table->float('consumed_qty',10,2)->default(0.00);
            $table->string('reduced_from',100)->nullable();
            $table->integer('batch_new')->default(0);
            $table->index(['type','trans_no']);
            $table->index(['stock_id','loc_code','tran_date'],'Move');
            $table->index('tid','sm_tid_idx');
            $table->index('unique_key','idx_unique_key');

        });
    }
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
