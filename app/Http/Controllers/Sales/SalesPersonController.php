<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesPersonController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(SalesPerson::orderBy('name')->get(), 'Sales persons retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:150',
            'telephone'        => 'nullable|string|max:50',
            'fax'              => 'nullable|string|max:50',
            'email'            => 'nullable|email|max:150',
            'provision_pct'    => 'nullable|numeric|min:0',
            'turnover_break_pt'=> 'nullable|numeric|min:0',
            'provision2_pct'   => 'nullable|numeric|min:0',
            'username'         => 'nullable|string|max:100',
        ]);
        return ApiResponse::created(SalesPerson::create($data), 'Sales person added');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $person = SalesPerson::findOrFail($id);
        $data   = $request->validate([
            'name'             => 'required|string|max:150',
            'telephone'        => 'nullable|string|max:50',
            'fax'              => 'nullable|string|max:50',
            'email'            => 'nullable|email|max:150',
            'provision_pct'    => 'nullable|numeric|min:0',
            'turnover_break_pt'=> 'nullable|numeric|min:0',
            'provision2_pct'   => 'nullable|numeric|min:0',
            'username'         => 'nullable|string|max:100',
        ]);
        $person->update($data);
        return ApiResponse::updated($person, 'Sales person updated');
    }

    public function destroy(int $id): JsonResponse
    {
        SalesPerson::findOrFail($id)->delete();
        return ApiResponse::deleted('Sales person deleted');
    }
}
