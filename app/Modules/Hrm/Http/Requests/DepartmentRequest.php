<?php

namespace App\Modules\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('hrm_departments', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'head_employee_id' => ['nullable', 'integer', 'exists:hrm_employees,id'],
            'inactive' => ['nullable', 'boolean'],
        ];
    }
}
