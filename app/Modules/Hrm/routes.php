<?php

use App\Modules\Hrm\Http\Controllers\DepartmentController;
use App\Modules\Hrm\Http\Controllers\EmployeeController;
use App\Modules\Hrm\Http\Controllers\JobTitleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HRM Module Routes
|--------------------------------------------------------------------------
| Loaded by HrmServiceProvider under the prefix /api/hrm and wrapped in
| ['api', 'auth:api', EnsureHrmEnabled]. Per-route permission middleware
| uses the backend verb-noun format (view-hrm → frontend hrm.view).
*/

// ── Employees ──────────────────────────────────────────────────────────────
Route::get('employees/form-data', [EmployeeController::class, 'formData'])->middleware('permission:view-hrm');
Route::get('employees', [EmployeeController::class, 'index'])->middleware('permission:view-hrm');
Route::get('employees/{id}', [EmployeeController::class, 'show'])->middleware('permission:view-hrm')->whereNumber('id');
Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:create-hrm');
Route::put('employees/{id}', [EmployeeController::class, 'update'])->middleware('permission:edit-hrm')->whereNumber('id');
Route::delete('employees/{id}', [EmployeeController::class, 'destroy'])->middleware('permission:delete-hrm')->whereNumber('id');

// ── Departments ────────────────────────────────────────────────────────────
Route::get('departments', [DepartmentController::class, 'index'])->middleware('permission:view-hrm');
Route::post('departments', [DepartmentController::class, 'store'])->middleware('permission:create-hrm');
Route::put('departments/{id}', [DepartmentController::class, 'update'])->middleware('permission:edit-hrm')->whereNumber('id');
Route::delete('departments/{id}', [DepartmentController::class, 'destroy'])->middleware('permission:delete-hrm')->whereNumber('id');

// ── Job Titles ─────────────────────────────────────────────────────────────
Route::get('job-titles', [JobTitleController::class, 'index'])->middleware('permission:view-hrm');
Route::post('job-titles', [JobTitleController::class, 'store'])->middleware('permission:create-hrm');
Route::put('job-titles/{id}', [JobTitleController::class, 'update'])->middleware('permission:edit-hrm')->whereNumber('id');
Route::delete('job-titles/{id}', [JobTitleController::class, 'destroy'])->middleware('permission:delete-hrm')->whereNumber('id');
