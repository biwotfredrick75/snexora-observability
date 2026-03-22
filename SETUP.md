# Backend Setup Guide

Quick start guide for setting up the VERP System Backend API.

## Prerequisites

- PHP 8.1 or higher
- Composer
- MySQL 8.0 or higher
- Laravel 11

## Step-by-Step Setup

### 1. Install Dependencies

```bash
cd backend
composer install
```

### 2. Environment Configuration

```bash
# Copy environment template
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 3. Configure Database

Edit `.env` file:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=verp_system
DB_USERNAME=root
DB_PASSWORD=
```

Create the database:

```bash
mysql -u root -p -e "CREATE DATABASE verp_system;"
```

### 4. Run Migrations

```bash
# Run all migrations
php artisan migrate

# Optionally seed the database
php artisan db:seed RolePermissionSeeder
```

### 5. Install & Publish Sanctum (if needed)

```bash
# Install Sanctum
composer require laravel/sanctum

# Publish Sanctum configuration
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"

# Run migrations
php artisan migrate
```

### 6. Install Spatie Permission (if needed)

```bash
# Install package
composer require spatie/laravel-permission

# Publish configuration
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# Run migrations
php artisan migrate
```

### 7. Start Development Server

```bash
# Terminal 1: Start Laravel server
php artisan serve

# Terminal 2 (optional): Watch for file changes
php artisan tinker
```

Server will be available at: `http://localhost:8000`

## Initial Testing

### Create Test User

```bash
php artisan tinker
```

```php
use App\Models\User;
use Illuminate\Support\Facades\Hash;

$user = User::create([
    'name' => 'Admin User',
    'email' => 'admin@example.com',
    'password' => Hash::make('password'),
    'phone' => '1234567890',
    'is_active' => true
]);

// Assign super_admin role
$user->assignRole('super_admin');

dd($user->load('roles'));
```

### Test Login

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'
```

## Project Structure

```
backend/
├── app/                           # Application code
│   ├── Http/
│   │   ├── Controllers/           # API controllers
│   │   ├── Middleware/            # Permission/Role middleware
│   │   └── Requests/              # Form validation requests
│   ├── Models/                    # Eloquent models
│   ├── Repositories/              # Data access layer
│   ├── Services/                  # Business logic
│   └── Providers/                 # Service providers
├── database/
│   ├── migrations/                # Database migrations
│   └── seeders/                   # Database seeders
├── routes/
│   └── api.php                    # API routes
├── config/                        # Configuration files
├── .env                           # Environment variables (created)
├── .env.example                   # Environment template
└── ARCHITECTURE.md                # Architecture documentation
```

## Common Commands

```bash
# Run database migrations
php artisan migrate

# Rollback migrations
php artisan migrate:rollback

# Seed database
php artisan db:seed RolePermissionSeeder

# Clear all caches
php artisan cache:clear
php artisan permission:cache-reset

# View available routes
php artisan route:list

# Run tests
php artisan test

# Generate documentation
php artisan scribe:generate
```

## Troubleshooting

### Database Connection Error

Check `.env` file MySQL configuration:
```bash
mysql -u root -p -e "SHOW DATABASES;"
```

### Permission Cache Issues

```bash
php artisan permission:cache-reset
```

### Missing Tables

```bash
# Check migration status
php artisan migrate:status

# Run pending migrations
php artisan migrate
```

### Sanctum Token Issues

Ensure Sanctum middleware is registered in `config/sanctum.php`:

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,127.0.0.1')),
```

## Development Workflow

1. **Create API Endpoint**
   - Create Controller in `app/Http/Controllers/`
   - Create FormRequest in `app/Http/Requests/`
   - Add route in `routes/api.php`

2. **Implement Business Logic**
   - Create Service in `app/Services/`
   - Use Repository in Service

3. **Handle Data Access**
   - Extend BaseRepository
   - Implement custom query methods

4. **Test Endpoint**
   - Use Postman or cURL
   - Test with valid/invalid tokens
   - Verify permissions

## API Authentication

All authenticated endpoints require:

```
Authorization: Bearer {token}
```

Get token from login endpoint:

```bash
POST /api/auth/login
{
  "email": "admin@example.com",
  "password": "password"
}
```

## Default Roles & Permissions

See `ARCHITECTURE.md` for complete documentation of roles and permissions.

## Next Steps

1. Configure frontend API URL in `.env`
2. Set up CORS if needed
3. Configure email settings for notifications
4. Set up API documentation (Scribe)
5. Configure webhooks for external services

## Support

For issues or questions, refer to:
- `ARCHITECTURE.md` - System architecture
- `README.md` - Laravel documentation
- Spatie Permission: https://spatie.be/docs/laravel-permission/v6/introduction
- Laravel Sanctum: https://laravel.com/docs/11.x/sanctum
