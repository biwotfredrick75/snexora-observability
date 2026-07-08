<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\HrLetter;
use App\Models\HrLetterTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HrLetterController extends Controller
{
    // ── Templates ────────────────────────────────────────────────────────────

    public function templates(): JsonResponse
    {
        $templates = HrLetterTemplate::where('inactive', false)->orderBy('name')->get();
        return ApiResponse::success($templates, 'Letter templates retrieved');
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:150',
            'category' => 'nullable|string|max:40',
            'body'     => 'required|string',
        ]);

        $template = HrLetterTemplate::create($data);

        return ApiResponse::created($template, 'Letter template created');
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $template = HrLetterTemplate::find($id);
        if (! $template) return ApiResponse::notFound('Template not found');

        $data = $request->validate([
            'name'     => 'sometimes|string|max:150',
            'category' => 'nullable|string|max:40',
            'body'     => 'sometimes|string',
            'inactive' => 'boolean',
        ]);

        $template->update($data);

        return ApiResponse::updated($template->fresh(), 'Letter template updated');
    }

    public function destroyTemplate(int $id): JsonResponse
    {
        $template = HrLetterTemplate::find($id);
        if (! $template) return ApiResponse::notFound('Template not found');

        $template->update(['inactive' => true]);

        return ApiResponse::deleted('Letter template deactivated');
    }

    // ── Issued letters ───────────────────────────────────────────────────────

    public function forEmployee(int $employeeId): JsonResponse
    {
        $letters = HrLetter::with('template:id,name')
            ->where('employee_id', $employeeId)
            ->orderByDesc('issued_at')
            ->get();

        return ApiResponse::success($letters, 'Letters retrieved');
    }

    public function generate(Request $request, int $employeeId, int $templateId): JsonResponse
    {
        $employee = Employee::with(['department', 'jobTitle'])->find($employeeId);
        if (! $employee) return ApiResponse::notFound('Employee not found');

        $template = HrLetterTemplate::find($templateId);
        if (! $template) return ApiResponse::notFound('Template not found');

        $company = DB::table('company_preferences')->first();

        $tokens = [
            '{{full_name}}'    => $employee->full_name,
            '{{emp_no}}'       => $employee->emp_no,
            '{{job_title}}'    => $employee->jobTitle?->name ?? '—',
            '{{department}}'   => $employee->department?->name ?? '—',
            '{{hire_date}}'    => $employee->hire_date?->format('d F Y') ?? '—',
            '{{basic_salary}}' => number_format((float) $employee->basic_salary, 2),
            '{{date}}'         => now()->format('d F Y'),
            '{{company_name}}'=> $company->name ?: 'the Company',
        ];

        $body = strtr($template->body, $tokens);

        $letter = HrLetter::create([
            'employee_id' => $employeeId,
            'template_id' => $templateId,
            'title'       => $template->name,
            'body'        => $body,
            'issued_by'   => Employee::where('user_id', Auth::user()?->user_id)->value('id'),
            'issued_at'   => now(),
        ]);

        return ApiResponse::created($letter->load('template:id,name'), 'Letter generated');
    }
}
