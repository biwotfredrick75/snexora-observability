<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Expand loc_code in stock_movements from char(20) to varchar(200)
        // SQL Server requires dropping dependent indexes before changing column size
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement("IF EXISTS (SELECT 1 FROM sys.indexes WHERE name='Move' AND object_id=OBJECT_ID('stock_movements')) DROP INDEX [Move] ON [stock_movements]");
        }
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('loc_code', 200)->default('')->change();
        });
        if (DB::getDriverName() === 'sqlsrv') {
            DB::statement('CREATE INDEX [Move] ON [stock_movements] ([loc_code])');
        }

        // Register WO goods issue and FG receipt transaction types
        $existing = DB::table('transaction_references')->pluck('id')->toArray();
        $isSqlSrv = DB::getDriverName() === 'sqlsrv';
        $now = now()->toDateTimeString();

        $rows = [];
        if (!in_array(40, $existing)) {
            $rows[] = "(40, 'wo_goods_issue', 'WO Goods Issue', 'WOI', '[PREFIX]-[YYYY]-[SEQ5]', 0, '$now', '$now')";
        }
        if (!in_array(41, $existing)) {
            $rows[] = "(41, 'wo_fg_receipt', 'WO FG Receipt', 'WOR', '[PREFIX]-[YYYY]-[SEQ5]', 0, '$now', '$now')";
        }

        if (!empty($rows)) {
            $cols = '(id, trans_type, trans_name, prefix, pattern, inactive, created_at, updated_at)';
            $vals = implode(',', $rows);
            if ($isSqlSrv) {
                DB::unprepared("SET IDENTITY_INSERT transaction_references ON; INSERT INTO transaction_references $cols VALUES $vals; SET IDENTITY_INSERT transaction_references OFF;");
            } else {
                DB::unprepared("INSERT INTO transaction_references $cols VALUES $vals");
            }
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->char('loc_code', 20)->default('')->change();
        });

        DB::table('transaction_references')->whereIn('id', [40, 41])->delete();
    }
};
