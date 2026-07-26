<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\OpenTelemetryServiceProvider::class,
    // App\Modules\Hrm\HrmServiceProvider::class,
    // ^ Disabled: superseded by the App\Http\Controllers\Hrm + App\Models
    //   (Department/JobTitle/Employee, plain `employees`/`departments`/
    //   `job_titles` tables) implementation registered in routes/api.php.
    //   The Payroll module (database/migrations/2026_06_22_010004_create_payroll_tables.php,
    //   App\Models\PayrollItem) was built against that table/model design
    //   (FK `employees.id`, App\Models\Employee), so this provider's
    //   `hrm_employees`/`hrm_departments`/`hrm_job_titles` tables and
    //   App\Modules\Hrm\Models\* classes were left un-migrated to avoid a
    //   route collision (both registered identical /api/hrm/* paths) and a
    //   split-brain employee dataset. The module's files are left in place
    //   in case they're wanted later — re-enable only after reconciling
    //   table names with the Payroll FKs.
];
