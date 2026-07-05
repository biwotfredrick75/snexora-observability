<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use Illuminate\Database\Seeder;

/**
 * HRM Seeder
 *
 * Seeds a handful of realistic departments, job titles, and employees so
 * the HRM module screens (EmployeeList, DepartmentList, JobTitleList) have
 * data to display during manual testing.
 *
 * NOT wired into DatabaseSeeder — run explicitly when needed:
 *   php8.3 artisan db:seed --class=HrmSeeder
 */
class HrmSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['code' => 'EXEC', 'name' => 'Executive',  'description' => 'Executive leadership'],
            ['code' => 'OPS',  'name' => 'Operations',  'description' => 'Plant and field operations'],
            ['code' => 'FIN',  'name' => 'Finance',     'description' => 'Finance & accounting'],
            ['code' => 'HR',   'name' => 'Human Resources', 'description' => 'People and culture'],
            ['code' => 'IT',   'name' => 'IT', 'description' => 'Information technology & systems'],
            ['code' => 'SALES','name' => 'Sales',       'description' => 'Sales & customer relations'],
        ];

        foreach ($departments as $dept) {
            Department::firstOrCreate(['code' => $dept['code']], $dept);
        }

        $jobTitles = [
            ['code' => 'CEO',  'name' => 'Chief Executive Officer', 'description' => 'Overall company leadership'],
            ['code' => 'OPM',  'name' => 'Operations Manager',      'description' => 'Oversees daily operations'],
            ['code' => 'ACC',  'name' => 'Accountant',              'description' => 'Financial record keeping'],
            ['code' => 'HRM',  'name' => 'HR Manager',              'description' => 'People management'],
            ['code' => 'ISA',  'name' => 'IT Systems Analyst',      'description' => 'Systems support & analysis'],
            ['code' => 'SOF',  'name' => 'Sales Officer',           'description' => 'Customer-facing sales role'],
            ['code' => 'PSV',  'name' => 'Plant Supervisor',        'description' => 'Supervises plant floor staff'],
        ];

        foreach ($jobTitles as $title) {
            JobTitle::firstOrCreate(['code' => $title['code']], $title);
        }

        $deptId = fn (string $code) => Department::where('code', $code)->value('id');
        $titleId = fn (string $code) => JobTitle::where('code', $code)->value('id');

        $employees = [
            [
                'emp_no' => 'EMP-0001', 'first_name' => 'Eusilah', 'last_name' => 'Mwende',
                'email' => 'eusilah.mwende@nexora.test', 'phone' => '0712000001',
                'department_id' => $deptId('EXEC'), 'job_title_id' => $titleId('CEO'),
                'employment_type' => 'permanent', 'hire_date' => '2018-01-15', 'status' => 'active',
                'basic_salary' => 350000, 'payment_method' => 'bank',
            ],
            [
                'emp_no' => 'EMP-0002', 'first_name' => 'Michael', 'last_name' => 'Johnson',
                'email' => 'michael.johnson@nexora.test', 'phone' => '0712000002',
                'department_id' => $deptId('OPS'), 'job_title_id' => $titleId('OPM'),
                'employment_type' => 'permanent', 'hire_date' => '2019-03-01', 'status' => 'active',
                'basic_salary' => 180000, 'payment_method' => 'bank',
            ],
            [
                'emp_no' => 'EMP-0003', 'first_name' => 'David', 'last_name' => 'Otieno',
                'email' => 'david.otieno@nexora.test', 'phone' => '0712000003',
                'department_id' => $deptId('FIN'), 'job_title_id' => $titleId('ACC'),
                'employment_type' => 'permanent', 'hire_date' => '2020-06-10', 'status' => 'active',
                'basic_salary' => 95000, 'payment_method' => 'bank',
            ],
            [
                'emp_no' => 'EMP-0004', 'first_name' => 'Sarah', 'last_name' => 'Muthoni',
                'email' => 'sarah.muthoni@nexora.test', 'phone' => '0712000004',
                'department_id' => $deptId('HR'), 'job_title_id' => $titleId('HRM'),
                'employment_type' => 'permanent', 'hire_date' => '2021-02-20', 'status' => 'active',
                'basic_salary' => 110000, 'payment_method' => 'bank',
            ],
            [
                'emp_no' => 'EMP-0005', 'first_name' => 'Faith', 'last_name' => 'Achieng',
                'email' => 'faith.achieng@nexora.test', 'phone' => '0712000005',
                'department_id' => $deptId('IT'), 'job_title_id' => $titleId('ISA'),
                'employment_type' => 'contract', 'hire_date' => '2022-09-05', 'status' => 'on_leave',
                'basic_salary' => 85000, 'payment_method' => 'mpesa',
            ],
            [
                'emp_no' => 'EMP-0006', 'first_name' => 'Anne', 'last_name' => 'Kamau',
                'email' => 'anne.kamau@nexora.test', 'phone' => '0712000006',
                'department_id' => $deptId('SALES'), 'job_title_id' => $titleId('SOF'),
                'employment_type' => 'permanent', 'hire_date' => '2023-01-10', 'status' => 'active',
                'basic_salary' => 70000, 'payment_method' => 'bank',
            ],
        ];

        foreach ($employees as $emp) {
            $emp['full_name'] = trim($emp['first_name'] . ' ' . ($emp['middle_name'] ?? '') . ' ' . $emp['last_name']);
            Employee::firstOrCreate(['emp_no' => $emp['emp_no']], $emp);
        }

        // Wire the CEO as manager for department heads (demonstrates the
        // self-referencing manager_id relation in EmployeeForm.vue's
        // "Reports To" dropdown).
        $ceoId = Employee::where('emp_no', 'EMP-0001')->value('id');
        Employee::whereIn('emp_no', ['EMP-0002', 'EMP-0003', 'EMP-0004', 'EMP-0005', 'EMP-0006'])
            ->update(['manager_id' => $ceoId]);
    }
}
