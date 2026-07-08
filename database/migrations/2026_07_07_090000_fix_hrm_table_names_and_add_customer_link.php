<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes a pre-existing mismatch: the live database has hrm_employees /
 * hrm_departments / hrm_job_titles (created by the now-disabled
 * App\Modules\Hrm migrations), but every active model/controller/FK
 * (App\Models\Employee, Payroll's employee_id FK, HrmKpiController, ...)
 * expects plain employees / departments / job_titles tables — so the
 * entire HRM+Payroll stack has been silently broken (table not found)
 * in this environment. Renames the tables in place to preserve existing
 * data, widens a couple of columns to match the current schema, backfills
 * the employees.full_name column the old table never had, and adds the
 * employee -> customer link used by the "convert to customer" action.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hrm_employees') && !Schema::hasTable('employees')) {
            Schema::rename('hrm_employees', 'employees');
        }
        if (Schema::hasTable('hrm_departments') && !Schema::hasTable('departments')) {
            Schema::rename('hrm_departments', 'departments');
        }
        if (Schema::hasTable('hrm_job_titles') && !Schema::hasTable('job_titles')) {
            Schema::rename('hrm_job_titles', 'job_titles');
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name', 80)->change();
            $table->string('middle_name', 80)->nullable()->change();
            $table->string('last_name', 80)->change();
            $table->string('email', 150)->nullable()->change();

            if (!Schema::hasColumn('employees', 'full_name')) {
                $table->string('full_name', 200)->nullable()->after('last_name');
            }
            if (!Schema::hasColumn('employees', 'customer_debtor_no')) {
                $table->string('customer_debtor_no', 10)->nullable()->after('user_id');
                $table->foreign('customer_debtor_no')->references('debtor_no')->on('customers')->nullOnDelete();
            }
        });

        DB::statement("
            UPDATE employees
            SET full_name = TRIM(CONCAT_WS(' ', first_name, middle_name, last_name))
            WHERE full_name IS NULL OR full_name = ''
        ");

        Schema::table('departments', function (Blueprint $table) {
            $table->string('name', 150)->change();
        });

        Schema::table('job_titles', function (Blueprint $table) {
            $table->string('name', 150)->change();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['customer_debtor_no']);
            $table->dropColumn('customer_debtor_no');
        });

        Schema::rename('employees', 'hrm_employees');
        Schema::rename('departments', 'hrm_departments');
        Schema::rename('job_titles', 'hrm_job_titles');
    }
};
