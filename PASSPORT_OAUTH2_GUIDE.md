# Passport OAuth2 API Security Guide

## Overview

Your API is now secured with **Laravel Passport**, an OAuth2 server implementation that allows:

- ✅ External systems to authenticate securely
- ✅ Token-based API access control
- ✅ Role-based permission checking
- ✅ Token expiration & refresh mechanisms
- ✅ Personal access tokens for integrations

## Architecture

```
External System
      ↓
    OAuth2 Password Grant
      ↓
Passport Client (client_id, client_secret)
      ↓
User Credentials (user_id, password)
      ↓
Access Token + Refresh Token
      ↓
Authenticated API Requests
```

## Setup Steps

### 1. Install Passport (Already Done ✅)
```bash
composer require laravel/passport
php artisan vendor:publish --provider="Laravel\Passport\PassportServiceProvider"
```

### 2. Run Migrations
```bash
php artisan migrate
```

This creates:
- `oauth_clients` - OAuth2 client applications
- `oauth_access_tokens` - Issued access tokens
- `oauth_refresh_tokens` - Refresh tokens for token renewal
- `oauth_personal_access_clients` - Personal access token clients

### 3. Generate Encryption Keys
```bash
php artisan passport:keys
```

Creates:
- `storage/oauth-private.key` - Private key for signing tokens
- `storage/oauth-public.key` - Public key for verification

### 4. Create OAuth2 Clients

#### For External Systems (Password Grant):
```bash
php artisan passport:client --password --name="External System API"
```

This will output:
```
Client ID: <client_id>
Client Secret: <client_secret>
```

Store these securely in your external system's configuration.

#### For Personal Access Tokens:
```bash
php artisan passport:client --personal --name="Personal Access Tokens"
```

### 5. Configure .env

Add Passport client credentials to your external system's .env:
```env
PASSPORT_CLIENT_ID=<client_id>
PASSPORT_CLIENT_SECRET=<client_secret>
PASSPORT_API_URL=https://your-api.com
```

## API Endpoints

### 1. Obtain Access Token (Public)

**Endpoint:** `POST /api/oauth/issue-token`

External systems use this to get an access token.

**Request:**
```json
{
  "user_id": "admin001",
  "password": "your_password",
  "scope": "*"
}
```

**Response (Success):**
```json
{
  "token_type": "Bearer",
  "expires_in": 31536000,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "refresh_token": "def50200..."
}
```

**Response (Error):**
```json
{
  "message": "The user credentials were incorrect."
}
```

### 2. Get Current User Info (Protected)

**Endpoint:** `GET /api/oauth/me`

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": "admin001",
    "real_name": "Admin User",
    "email": "admin@example.com",
    "phone": "+254712345678",
    "role_id": 1,
    "roles": ["Super Admin"],
    "permissions": ["view-users", "create-users", ...],
    "inactive": false,
    "created_at": "2024-02-23T10:00:00Z"
  }
}
```

### 3. Refresh Access Token (Protected)

**Endpoint:** `POST /api/oauth/refresh-token`

When access token expires, use refresh token to get a new one.

**Request:**
```json
{
  "refresh_token": "def50200..."
}
```

**Response:**
```json
{
  "token_type": "Bearer",
  "expires_in": 31536000,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "refresh_token": "def50200..."
}
```

### 4. List User Tokens (Protected)

**Endpoint:** `GET /api/oauth/tokens`

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": "1abc23de456",
      "name": "Mobile App",
      "scopes": ["*"],
      "revoked": false,
      "created_at": "2024-02-23T10:00:00Z",
      "expires_at": "2025-02-23T10:00:00Z"
    }
  ]
}
```

### 5. Create Personal Access Token (Protected)

**Endpoint:** `POST /api/oauth/tokens/personal`

For long-lived integrations that don't require refresh tokens.

**Request:**
```json
{
  "token_name": "ERP Integration",
  "scopes": ["*"]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Personal access token created",
  "data": {
    "token_id": "1abc23de456",
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "token_type": "Bearer",
    "expires_at": "2026-08-23T10:00:00Z"
  }
}
```

### 6. Revoke Specific Token (Protected)

**Endpoint:** `POST /api/oauth/tokens/revoke`

**Request:**
```json
{
  "token_id": "1abc23de456"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Token revoked successfully"
}
```

### 7. Logout (Revoke All Tokens) (Protected)

**Endpoint:** `POST /api/oauth/logout`

Revokes all tokens for the current user.

**Headers:**
```
Authorization: Bearer {access_token}
```

**Response:**
```json
{
  "success": true,
  "message": "Successfully logged out"
}
```

## Token Expiration Configuration

Edit `app/Providers/AppServiceProvider.php`:

```php
public function boot(): void
{
    // Access token expires in 15 days
    \Laravel\Passport\Passport::tokensExpireIn(now()->addDays(15));
    
    // Refresh token expires in 30 days
    \Laravel\Passport\Passport::refreshTokensExpireIn(now()->addDays(30));
    
    // Personal access tokens expire in 6 months
    \Laravel\Passport\Passport::personalAccessTokensExpireIn(now()->addMonths(6));
    
    // Enable password grant for user credentials flow
    \Laravel\Passport\Passport::enablePasswordGrant();
}
```

## External System Integration Example

### Node.js / JavaScript
```javascript
// Get access token
const response = await fetch('https://api.example.com/api/oauth/issue-token', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    user_id: 'admin001',
    password: 'password123',
    scope: '*'
  })
});

const { access_token, refresh_token } = await response.json();

// Use token for API requests
const apiResponse = await fetch('https://api.example.com/api/oauth/me', {
  headers: { 'Authorization': `Bearer ${access_token}` }
});

const user = await apiResponse.json();
console.log(user);
```

### Python
```python
import requests

# Get access token
response = requests.post('https://api.example.com/api/oauth/issue-token', json={
    'user_id': 'admin001',
    'password': 'password123',
    'scope': '*'
})

data = response.json()
access_token = data['access_token']

# Use token for API requests
headers = {'Authorization': f'Bearer {access_token}'}
response = requests.get('https://api.example.com/api/oauth/me', headers=headers)
user = response.json()
print(user)
```

### C# / .NET
```csharp
using System.Net.Http;
using System.Net.Http.Json;

var client = new HttpClient();

// Get access token
var tokenResponse = await client.PostAsJsonAsync(
    "https://api.example.com/api/oauth/issue-token",
    new { user_id = "admin001", password = "password123", scope = "*" }
);

var tokenData = await tokenResponse.Content.ReadAsAsync<dynamic>();
var accessToken = tokenData["access_token"];

// Use token for API requests
client.DefaultRequestHeaders.Add("Authorization", $"Bearer {accessToken}");
var response = await client.GetAsync("https://api.example.com/api/oauth/me");
var user = await response.Content.ReadAsAsync<dynamic>();
```

### PHP (Laravel)
```php
use Illuminate\Support\Facades\Http;

// Get access token
$response = Http::post('https://api.example.com/api/oauth/issue-token', [
    'user_id' => 'admin001',
    'password' => 'password123',
    'scope' => '*'
]);

$accessToken = $response['access_token'];

// Use token for API requests
$user = Http::withToken($accessToken)
    ->get('https://api.example.com/api/oauth/me');
```

## Security Best Practices

### 1. Store Credentials Securely
- Never hardcode credentials in code
- Use environment variables
- Use secure configuration management systems
- For sensitive systems, use secrets management (HashiCorp Vault, AWS Secrets Manager)

### 2. Token Management
- Tokens are sensitive data - treat like passwords
- Store tokens securely (encrypted storage)
- Always use HTTPS for token transmission
- Don't log tokens in plain text

### 3. Scope Limiting
- Use minimal scopes required (not always `*`)
- Create custom scopes for different integrations
- Example: `read:users`, `write:orders`, `delete:reports`

### 4. Rate Limiting
Add rate limiting to protect against abuse:
```php
// In routes/api.php
Route::middleware('throttle:60,1')->group(function () {
    Route::post('oauth/issue-token', ...);
});
```

### 5. Audit Logging
Log all token issuance and revocation:
```php
Log::info('Token issued', [
    'user_id' => $user->user_id,
    'client_id' => $clientId,
    'ip' => request()->ip(),
]);
```

### 6. HTTPS Only
- Always enforce HTTPS in production
- Set in `config/session.php`:
```php
'secure' => env('SESSION_SECURE_COOKIES', true),
'http_only' => true,
```

### 7. CORS Configuration
Configure CORS for external API access in `config/cors.php`:
```php
'paths' => ['api/*'],
'allowed_origins' => ['https://trusted-system.com'],
'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],
```

## Troubleshooting

### "Invalid client credentials"
- Verify client_id and client_secret
- Check if client exists: `php artisan tinker`
  ```php
  DB::table('oauth_clients')->get();
  ```

### "User credentials invalid"
- Verify user_id and password are correct
- Check user is not inactive: `user->inactive == 0`

### "Token has expired"
- Use refresh token to get new access token
- Or re-authenticate to get new token

### "Invalid refresh token"
- Refresh token may have expired (30 days)
- Re-authenticate with user credentials

## Next Steps

1. ✅ Generate encryption keys: `php artisan passport:keys`
2. ✅ Run migrations: `php artisan migrate`
3. ✅ Create OAuth2 client: `php artisan passport:client --password`
4. ✅ Share client credentials with external systems
5. ✅ Document API endpoints for external developers
6. ✅ Implement rate limiting & logging
7. ✅ Set up monitoring for token usage

---

**Document Version:** 1.0  
**Last Updated:** February 23, 2024  
**Status:** Production Ready
