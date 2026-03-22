# VERP System - Backend API

A comprehensive Laravel-based backend API for the VERP (Village Enterprise Resource Planning) System with Authentication, Authorization, and Role-Based Access Control (RBAC).

## Architecture Overview

This backend follows a **Repository Service Pattern** architecture with clear separation of concerns:

### Layers

1. **Controllers** (`app/Http/Controllers/`)
   - HTTP request handlers
   - Orchestrate request/response flow
   - Minimal business logic

2. **Services** (`app/Services/`)
   - Core business logic
   - Handle authentication and authorization
   - Manage role and permission operations
   - Decouple controllers from repositories

3. **Repositories** (`app/Repositories/`)
   - Abstract data access layer
   - Implement CRUD operations
   - Query optimization
   - Extensible for different data sources

4. **Models** (`app/Models/`)
   - Eloquent ORM models
   - Database relationships
   - Model-specific logic

### Key Components

#### Authentication System

- **JWT Token-based** using Laravel Sanctum
- Email and password authentication
- User status management (active/inactive)
- Secure password hashing

#### Authorization System (RBAC)

- **Role-Based Access Control** using Spatie Permission
- Hierarchical permission structure
- Dynamic role and permission assignment
- Permission caching for performance

#### Database Design

**Users Table**
```
- id (PK)
- name
- email (unique)
- password (hashed)
- phone
- is_active
- email_verified_at
- timestamps
```

**Roles & Permissions**
- Roles: Admin, Super Admin, Sales Manager, Purchase Manager, Inventory Manager, User
- Permissions: Granular per-module (view, create, edit, delete)
- Role-Permission: Many-to-many relationships

## Installation

### Prerequisites

- PHP 8.1+
- Composer
- MySQL 8.0+
- Laravel 11+

### Setup Steps

1. **Install Dependencies**
   ```bash
   cd backend
   composer install
   ```

2. **Environment Configuration**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Database Setup**
   ```bash
   # Create database
   mysql -u root -p -e "CREATE DATABASE verp_system;"
   
   # Run migrations
   php artisan migrate
   
   # Seed roles and permissions
   php artisan db:seed RolePermissionSeeder
   ```

4. **Install Sanctum** (if not already installed)
   ```bash
   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
   php artisan migrate
   ```

5. **Start Server**
   ```bash
   php artisan serve
   ```

## API Endpoints

### Authentication

#### Login
```
POST /api/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password"
}

Response:
{
  "message": "Login successful",
  "user": { ... },
  "token": "..."
}
```

#### Register
```
POST /api/auth/register
Content-Type: application/json

{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password",
  "password_confirmation": "password"
}
```

#### Get Current User
```
GET /api/auth/me
Authorization: Bearer {token}
```

#### Logout
```
POST /api/auth/logout
Authorization: Bearer {token}
```

### Role Management

#### Get All Roles
```
GET /api/roles
Authorization: Bearer {token}
Required Permission: view-roles
```

#### Create Role
```
POST /api/roles
Authorization: Bearer {token}
Required Permission: create-roles

{
  "name": "editor",
  "display_name": "Editor",
  "description": "Can edit content"
}
```

#### Get Role with Permissions
```
GET /api/roles/{roleName}/permissions
Authorization: Bearer {token}
Required Permission: view-roles
```

#### Delete Role
```
DELETE /api/roles/{roleName}
Authorization: Bearer {token}
Required Permission: delete-roles
```

### Permission Management

#### Get All Permissions
```
GET /api/permissions
Authorization: Bearer {token}
Required Permission: view-permissions
```

#### Create Permission
```
POST /api/permissions
Authorization: Bearer {token}
Required Permission: create-permissions

{
  "name": "edit-users",
  "display_name": "Edit Users",
  "description": "Can edit user accounts"
}
```

#### Delete Permission
```
DELETE /api/permissions/{permissionName}
Authorization: Bearer {token}
Required Permission: delete-permissions
```

#### Assign Permission to Role
```
POST /api/roles/permissions/assign
Authorization: Bearer {token}
Required Permission: assign-roles

{
  "role_name": "editor",
  "permission_name": "edit-users"
}
```

## Directory Structure

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   ├── AuthController.php
│   │   │   │   └── RolePermissionController.php
│   │   ├── Middleware/
│   │   │   ├── CheckPermission.php
│   │   │   └── CheckRole.php
│   │   └── Requests/
│   │       └── Auth/
│   │           ├── LoginRequest.php
│   │           ├── RegisterRequest.php
│   │           └── ...
│   ├── Models/
│   │   └── User.php
│   ├── Repositories/
│   │   ├── BaseRepository.php
│   │   ├── Contracts/
│   │   │   └── RepositoryInterface.php
│   │   └── Auth/
│   │       └── UserRepository.php
│   ├── Services/
│   │   └── Auth/
│   │       ├── AuthService.php
│   │       └── RolePermissionService.php
│   └── Providers/
│       └── AppServiceProvider.php
├── database/
│   ├── migrations/
│   └── seeders/
│       └── RolePermissionSeeder.php
├── routes/
│   └── api.php
├── config/
│   ├── app.php
│   └── permission.php
└── .env
```

## Default Roles

### Super Admin
- All permissions enabled
- Full system access

### Admin
- User and role viewing
- Dashboard access
- Module viewing (sales, purchases, inventory, etc.)

### Sales Manager
- Dashboard and sales management
- Can create and edit sales
- Farmer and inventory viewing

### Purchase Manager
- Dashboard and purchase management
- Can create and edit purchases
- Farmer viewing

### Inventory Manager
- Dashboard and inventory management
- Full inventory control

### User
- Dashboard access only

## Usage Examples

### Creating a User with Role

```php
// In a service or controller
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => Hash::make('password'),
    'phone' => '1234567890'
]);

// Assign role
$user->assignRole('sales_manager');

// Check permission
if ($user->hasPermissionTo('create-sales')) {
    // Can create sales
}
```

### Creating Custom Roles and Permissions

```php
// Via service
$rolePermissionService = app(\App\Services\Auth\RolePermissionService::class);

// Create permission
$permission = $rolePermissionService->createPermission('export-reports');

// Create role
$role = $rolePermissionService->createRole('reporter');

// Assign permission to role
$rolePermissionService->givePermissionToRole('reporter', 'export-reports');
```

## Security Features

1. **Sanctum Token Authentication**
   - API token-based authentication
   - CSRF protection
   - Stateless requests

2. **Permission Middleware**
   - Route-level permission checking
   - Automatic 403 responses for unauthorized access

3. **Role-Based Middleware**
   - Route-level role checking
   - Flexible authorization

4. **Password Security**
   - Bcrypt hashing
   - Secure password validation

5. **Database Indexing**
   - Optimized queries for email lookups
   - Fast permission checks

## Testing

```bash
# Run tests
php artisan test

# Run tests with coverage
php artisan test --coverage

# Run specific test file
php artisan test tests/Feature/AuthTest.php
```

## Performance Optimization

1. **Permission Caching**
   - Spatie Permission caches role/permission relationships
   - Cache invalidation on updates

2. **Eager Loading**
   - Relationships are eager loaded to avoid N+1 queries
   - Custom queries optimize data retrieval

3. **Database Indexing**
   - Foreign keys indexed
   - Common lookup columns indexed

## Contributing

When adding new modules:

1. Create repository in `app/Repositories/{Module}/`
2. Create service in `app/Services/{Module}/`
3. Create controller in `app/Http/Controllers/{Module}/`
4. Define routes in `routes/api.php`
5. Add permissions in `RolePermissionSeeder`

## License

MIT License
