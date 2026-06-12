<?php

namespace App\Modules\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JobTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('hrm_job_titles', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'inactive' => ['nullable', 'boolean'],
        ];
    }
}
