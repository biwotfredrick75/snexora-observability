<?php

namespace App\Modules\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'emp_no' => ['nullable', 'string', 'max:30', Rule::unique('hrm_employees', 'emp_no')->ignore($id)],
            'first_name' => ['required', 'string', 'max:60'],
            'middle_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'gender' => ['nullable', 'string', 'max:12'],
            'date_of_birth' => ['nullable', 'date'],
            'national_id' => ['nullable', 'string', 'max:30', Rule::unique('hrm_employees', 'national_id')->ignore($id)],

            // Kenya statutory identifiers
            'kra_pin' => ['nullable', 'string', 'max:20'],
            'nssf_no' => ['nullable', 'string', 'max:30'],
            'shif_no' => ['nullable', 'string', 'max:30'],
            'nita_no' => ['nullable', 'string', 'max:30'],
            'helb_no' => ['nullable', 'string', 'max:30'],

            'email' => ['nullable', 'email', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'physical_address' => ['nullable', 'string'],

            'department_id' => ['nullable', 'integer', 'exists:hrm_departments,id'],
            'job_title_id' => ['nullable', 'integer', 'exists:hrm_job_titles,id'],
            'manager_id' => ['nullable', 'integer', 'exists:hrm_employees,id'],
            'user_id' => ['nullable', 'string', 'max:60'],

            'employment_type' => ['required', Rule::in(['permanent', 'contract', 'casual', 'intern'])],
            'hire_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'status' => ['required', Rule::in(['active', 'on_leave', 'suspended', 'terminated', 'inactive'])],

            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(['bank', 'mpesa', 'cash'])],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'bank_branch' => ['nullable', 'string', 'max:80'],
            'bank_account' => ['nullable', 'string', 'max:40'],

            'notes' => ['nullable', 'string'],
        ];
    }
}
