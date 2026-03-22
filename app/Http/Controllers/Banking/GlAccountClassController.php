<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GlAccountClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlAccountClassController extends Controller
{
    /** GET /api/banking/gl-classes */
    public function index(Request $request): JsonResponse
    {
        $query = GlAccountClass::orderBy('id');

        if (!$request->boolean('show_inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get()->toArray(), 'GL classes retrieved');
    }

    /** POST /api/banking/gl-classes */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id'         => 'required|integer|min:1|unique:gl_account_classes,id',
            'name'       => 'required|string|max:100',
            'class_type' => 'required|in:Assets,Liabilities,Equity,Income,Expense',
            'inactive'   => 'sometimes|boolean',
        ]);

        $class = GlAccountClass::create($validated);

        return ApiResponse::created($class, 'Class created');
    }

    /** PUT /api/banking/gl-classes/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $class = GlAccountClass::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'class_type' => 'sometimes|in:Assets,Liabilities,Equity,Income,Expense',
            'inactive'   => 'sometimes|boolean',
        ]);

        $class->update($validated);

        return ApiResponse::updated($class->fresh(), 'Class updated');
    }

    /** DELETE /api/banking/gl-classes/{id} */
    public function destroy(int $id): JsonResponse
    {
        GlAccountClass::findOrFail($id)->delete();

        return ApiResponse::deleted('Class deleted');
    }
}
