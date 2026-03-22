# API Response Class - Usage Guide

## Overview

The `ApiResponse` class provides a **uniform, well-structured response format** for all API endpoints. It ensures consistency across your entire API.

## Response Structure

### Success Response
```json
{
  "success": true,
  "message": "Request successful",
  "data": {...},
  "meta": {...}
}
```

### Error Response
```json
{
  "success": false,
  "message": "Error message",
  "error_code": "ERROR_CODE",
  "errors": {...}
}
```

---

## Basic Methods

### Success Response

**Simple success:**
```php
return ApiResponse::success($data);
// Returns 200 status

return ApiResponse::success($data, 'Custom message');
return ApiResponse::success($data, 'Custom message', 200);
```

**With metadata:**
```php
return ApiResponse::success(
    data: $user,
    message: 'User retrieved',
    statusCode: 200,
    meta: ['version' => '1.0']
);
```

### Error Response

**Basic error:**
```php
return ApiResponse::error('Something went wrong');
// Returns 400 status

return ApiResponse::error('Error message', 422);
return ApiResponse::error('Error message', 422, ['field' => 'error']);
```

**With error code:**
```php
return ApiResponse::error(
    message: 'Duplicate entry',
    statusCode: 409,
    errors: null,
    errorCode: 'DUPLICATE_ENTRY'
);
```

---

## HTTP Status-Specific Methods

### Created (201)
```php
return ApiResponse::created($user, 'User created successfully');
// Returns 201 Created
```

### Updated
```php
return ApiResponse::updated($user, 'User updated successfully');
// Returns 200 OK
```

### Deleted
```php
return ApiResponse::deleted('User deleted successfully');
// Returns 200 OK (no data)
```

### Unauthorized (401)
```php
return ApiResponse::unauthorized('Invalid credentials');
// Returns 401 Unauthorized
```

### Forbidden (403)
```php
return ApiResponse::forbidden('You do not have permission');
// Returns 403 Forbidden
```

### Not Found (404)
```php
return ApiResponse::notFound('User not found');
// Or with resource type:
return ApiResponse::notFound(resource: 'User');
// Returns 404 Not Found
```

### Conflict (409)
```php
return ApiResponse::conflict('User already exists');
// Returns 409 Conflict
```

### Too Many Requests (429)
```php
return ApiResponse::tooManyRequests('Rate limit exceeded');
// Returns 429 Too Many Requests
```

### Server Error (500)
```php
return ApiResponse::serverError('Database connection failed');
// Or with error code:
return ApiResponse::serverError('Database error', 'DB_ERROR');
// Returns 500 Internal Server Error
```

### Service Unavailable (503)
```php
return ApiResponse::serviceUnavailable('Maintenance in progress');
// Returns 503 Service Unavailable
```

---

## Validation Error Response

```php
$validated = $request->validate([
    'email' => 'required|email',
    'name' => 'required|string',
]);

// If validation fails:
return ApiResponse::validationError([
    'email' => ['Email is required'],
    'name' => ['Name is required'],
], 'Please fix validation errors');
// Returns 422 Unprocessable Entity
```

---

## Paginated Response

### With Laravel Paginator
```php
$users = User::paginate(15);

return ApiResponse::paginated($users, 'Users retrieved');
```

**Returns:**
```json
{
  "success": true,
  "message": "Users retrieved",
  "data": [...],
  "meta": {
    "pagination": {
      "total": 100,
      "count": 15,
      "per_page": 15,
      "current_page": 1,
      "last_page": 7,
      "from": 1,
      "to": 15
    }
  }
}
```

---

## Collection Response

```php
$items = [
    ['id' => 1, 'name' => 'Item 1'],
    ['id' => 2, 'name' => 'Item 2'],
];

return ApiResponse::collection($items, count($items), 'Items retrieved');
```

**Returns:**
```json
{
  "success": true,
  "message": "Items retrieved",
  "data": [...],
  "meta": {
    "count": 2,
    "items": 2
  }
}
```

---

## Bulk Operation Response

```php
return ApiResponse::bulkOperation(
    total: 100,
    successful: 95,
    failed: 5,
    errors: [
        ['id' => 1, 'error' => 'Invalid data'],
        ['id' => 2, 'error' => 'Duplicate entry'],
    ],
    message: 'Bulk import completed'
);
```

**Returns:**
```json
{
  "success": true,
  "message": "Bulk import completed",
  "data": null,
  "meta": {
    "bulk_operation": {
      "total": 100,
      "successful": 95,
      "failed": 5,
      "errors": [...]
    }
  }
}
```

---

## Real-World Controller Examples

### AuthController
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthService;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->authenticate(
                $request->input('user_id'),
                $request->input('password')
            );

            if (!$result['success']) {
                return ApiResponse::unauthorized('Invalid credentials');
            }

            return ApiResponse::success(
                $result['data'],
                'Login successful',
                200
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage());
        }
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();
        return ApiResponse::deleted('Logged out successfully');
    }

    public function me(): JsonResponse
    {
        $user = auth('api')->user();
        
        if (!$user) {
            return ApiResponse::unauthorized('User not authenticated');
        }

        return ApiResponse::success($user, 'User retrieved');
    }
}
```

### UserController
```php
<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::paginate(15);
        return ApiResponse::paginated($users, 'Users retrieved');
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        try {
            $user = User::create($request->validated());
            return ApiResponse::created($user, 'User created successfully');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to create user');
        }
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success($user, 'User retrieved');
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        try {
            $user->update($request->validated());
            return ApiResponse::updated($user, 'User updated successfully');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to update user');
        }
    }

    public function destroy(User $user): JsonResponse
    {
        try {
            $user->delete();
            return ApiResponse::deleted('User deleted successfully');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to delete user');
        }
    }
}
```

### RolePermissionController
```php
<?php

namespace App\Http\Controllers\Auth;

use App\Http\Responses\ApiResponse;
use Spatie\Permission\Models\Role;
use Illuminate\Http\JsonResponse;

class RolePermissionController extends Controller
{
    public function getAllRoles(): JsonResponse
    {
        try {
            $roles = Role::with('permissions')->get();
            return ApiResponse::collection(
                $roles->toArray(),
                $roles->count(),
                'Roles retrieved'
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to retrieve roles');
        }
    }

    public function createRole(CreateRoleRequest $request): JsonResponse
    {
        try {
            $role = Role::create($request->validated());
            return ApiResponse::created($role, 'Role created successfully');
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                return ApiResponse::conflict('Role already exists');
            }
            return ApiResponse::serverError('Failed to create role');
        }
    }

    public function deleteRole(string $roleName): JsonResponse
    {
        try {
            $role = Role::findByName($roleName);
            
            if (!$role) {
                return ApiResponse::notFound('Role not found');
            }

            $role->delete();
            return ApiResponse::deleted('Role deleted successfully');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to delete role');
        }
    }
}
```

---

## Response Examples

### Success with Data
**Request:** `GET /api/users/1`

```json
{
  "success": true,
  "message": "User retrieved",
  "data": {
    "id": 1,
    "user_id": "admin001",
    "real_name": "Admin User",
    "email": "admin@example.com",
    "role_id": 1
  }
}
```

### Success with Pagination
**Request:** `GET /api/users?page=1`

```json
{
  "success": true,
  "message": "Users retrieved",
  "data": [
    {"id": 1, "user_id": "admin001", ...},
    {"id": 2, "user_id": "user001", ...}
  ],
  "meta": {
    "pagination": {
      "total": 100,
      "count": 15,
      "per_page": 15,
      "current_page": 1,
      "last_page": 7
    }
  }
}
```

### Validation Error
**Request:** `POST /api/users` (missing required fields)

```json
{
  "success": false,
  "message": "Validation failed",
  "error_code": "VALIDATION_ERROR",
  "errors": {
    "email": ["The email field is required"],
    "password": ["The password must be at least 8 characters"]
  }
}
```

### Not Found
**Request:** `GET /api/users/999`

```json
{
  "success": false,
  "message": "User not found",
  "error_code": "NOT_FOUND"
}
```

### Unauthorized
**Request:** `GET /api/admin/settings` (without token)

```json
{
  "success": false,
  "message": "Unauthorized",
  "error_code": "UNAUTHORIZED"
}
```

### Server Error
**Request:** Any request causing internal error

```json
{
  "success": false,
  "message": "Internal server error",
  "error_code": "SERVER_ERROR"
}
```

---

## HTTP Status Codes

| Method | Status | Use Case |
|--------|--------|----------|
| `success()` | 200 | General success responses |
| `created()` | 201 | Resource creation |
| `updated()` | 200 | Resource updates |
| `deleted()` | 200 | Resource deletion |
| `validationError()` | 422 | Validation failures |
| `unauthorized()` | 401 | Missing/invalid authentication |
| `forbidden()` | 403 | Authenticated but no permission |
| `notFound()` | 404 | Resource doesn't exist |
| `conflict()` | 409 | Resource conflict (duplicate, etc.) |
| `tooManyRequests()` | 429 | Rate limit exceeded |
| `serverError()` | 500 | Server error |
| `serviceUnavailable()` | 503 | Service down/maintenance |

---

## Best Practices

### 1. Always Use Appropriate Methods
```php
// ❌ Don't do this
return response()->json(['success' => true, 'data' => $user]);

// ✅ Do this
return ApiResponse::success($user);
```

### 2. Include Error Codes
```php
// ✅ Good
return ApiResponse::error('Email already exists', 409, null, 'DUPLICATE_EMAIL');

// ✅ Also good
return ApiResponse::conflict('Email already exists');
```

### 3. Use Specific HTTP Status Codes
```php
// ❌ Don't use 400 for everything
return ApiResponse::error('Not found', 400);

// ✅ Use appropriate status
return ApiResponse::notFound('User not found');
```

### 4. Include Helpful Messages
```php
// ❌ Generic
return ApiResponse::error('Error');

// ✅ Descriptive
return ApiResponse::serverError('Failed to send email verification');
```

### 5. Consistent Error Structure
```php
// ✅ Good - errors are consistent
return ApiResponse::validationError([
    'email' => ['Email is invalid'],
    'password' => ['Password is too weak'],
]);
```

---

## Testing API Responses

```php
// In tests/Feature/ApiTest.php

public function test_successful_response()
{
    $response = $this->get('/api/users/1');
    
    $response->assertStatus(200);
    $response->assertJson([
        'success' => true,
        'message' => 'User retrieved',
    ]);
}

public function test_error_response()
{
    $response = $this->get('/api/users/999');
    
    $response->assertStatus(404);
    $response->assertJson([
        'success' => false,
        'error_code' => 'NOT_FOUND',
    ]);
}

public function test_validation_error()
{
    $response = $this->post('/api/users', []);
    
    $response->assertStatus(422);
    $response->assertJson([
        'success' => false,
        'error_code' => 'VALIDATION_ERROR',
    ]);
}
```

---

## Summary

The `ApiResponse` class provides:
- ✅ **Consistency** - All responses follow the same structure
- ✅ **Flexibility** - Methods for every common scenario
- ✅ **Type Safety** - Proper HTTP status codes
- ✅ **Error Handling** - Clear error messages and codes
- ✅ **Metadata** - Support for pagination and bulk operations
- ✅ **Maintainability** - Easy to extend and customize

Always use `ApiResponse` instead of raw `response()->json()` for consistency!
