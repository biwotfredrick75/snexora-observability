# API Testing Guide - Postman Collection

## Import into Postman

Save this collection as `VERP-API.postman_collection.json` and import into Postman.

## Environment Variables

Create a Postman environment with these variables:

```json
{
  "name": "VERP API Dev",
  "values": [
    {
      "key": "API_URL",
      "value": "http://localhost:8000",
      "enabled": true
    },
    {
      "key": "ACCESS_TOKEN",
      "value": "",
      "enabled": true
    },
    {
      "key": "REFRESH_TOKEN",
      "value": "",
      "enabled": true
    },
    {
      "key": "CLIENT_ID",
      "value": "",
      "enabled": true
    },
    {
      "key": "CLIENT_SECRET",
      "value": "",
      "enabled": true
    }
  ]
}
```

## Test Collection

### 1. Get OAuth2 Access Token

**Method:** POST  
**URL:** `{{API_URL}}/api/oauth/issue-token`

**Body (JSON):**
```json
{
  "user_id": "admin001",
  "password": "password123",
  "scope": "*"
}
```

**Pre-request Script:**
```javascript
// None needed
```

**Tests:**
```javascript
if (pm.response.code === 200) {
  var jsonData = pm.response.json();
  pm.environment.set("ACCESS_TOKEN", jsonData.access_token);
  pm.environment.set("REFRESH_TOKEN", jsonData.refresh_token);
  pm.test("Status code is 200", function () {
    pm.response.to.have.status(200);
  });
  pm.test("Has access token", function () {
    pm.expect(jsonData).to.have.property('access_token');
  });
  pm.test("Has refresh token", function () {
    pm.expect(jsonData).to.have.property('refresh_token');
  });
}
```

---

### 2. Get Current User Info

**Method:** GET  
**URL:** `{{API_URL}}/api/oauth/me`

**Headers:**
- `Authorization`: `Bearer {{ACCESS_TOKEN}}`

**Tests:**
```javascript
pm.test("Status code is 200", function () {
  pm.response.to.have.status(200);
});
pm.test("Response has user data", function () {
  var jsonData = pm.response.json();
  pm.expect(jsonData.success).to.equal(true);
  pm.expect(jsonData.data).to.have.property('user_id');
});
```

---

### 3. Get User Tokens

**Method:** GET  
**URL:** `{{API_URL}}/api/oauth/tokens`

**Headers:**
- `Authorization`: `Bearer {{ACCESS_TOKEN}}`

**Tests:**
```javascript
pm.test("Status code is 200", function () {
  pm.response.to.have.status(200);
});
pm.test("Response is array", function () {
  var jsonData = pm.response.json();
  pm.expect(jsonData.data).to.be.an('array');
});
```

---

### 4. Refresh Access Token

**Method:** POST  
**URL:** `{{API_URL}}/api/oauth/refresh-token`

**Body (JSON):**
```json
{
  "refresh_token": "{{REFRESH_TOKEN}}"
}
```

**Tests:**
```javascript
if (pm.response.code === 200) {
  var jsonData = pm.response.json();
  pm.environment.set("ACCESS_TOKEN", jsonData.access_token);
  pm.test("Status code is 200", function () {
    pm.response.to.have.status(200);
  });
}
```

---

### 5. Create Personal Access Token

**Method:** POST  
**URL:** `{{API_URL}}/api/oauth/tokens/personal`

**Headers:**
- `Authorization`: `Bearer {{ACCESS_TOKEN}}`

**Body (JSON):**
```json
{
  "token_name": "Mobile App Integration",
  "scopes": ["*"]
}
```

**Tests:**
```javascript
pm.test("Status code is 201", function () {
  pm.response.to.have.status(201);
});
pm.test("Has access token", function () {
  var jsonData = pm.response.json();
  pm.expect(jsonData.data).to.have.property('access_token');
});
```

---

### 6. Logout (Revoke All Tokens)

**Method:** POST  
**URL:** `{{API_URL}}/api/oauth/logout`

**Headers:**
- `Authorization`: `Bearer {{ACCESS_TOKEN}}`

**Tests:**
```javascript
pm.test("Status code is 200", function () {
  pm.response.to.have.status(200);
});
pm.test("Logout successful", function () {
  var jsonData = pm.response.json();
  pm.expect(jsonData.success).to.equal(true);
});
```

---

## Testing Scenarios

### Scenario 1: Complete Authentication Flow

1. **Get Token** - POST `/api/oauth/issue-token`
2. **Get User** - GET `/api/oauth/me`
3. **List Tokens** - GET `/api/oauth/tokens`
4. **Logout** - POST `/api/oauth/logout`
5. **Try to Get User Again** - GET `/api/oauth/me` (should fail with 401)

### Scenario 2: Token Refresh Flow

1. **Get Token** - Save access_token and refresh_token
2. **Simulate Expiration** - Clear ACCESS_TOKEN environment variable
3. **Refresh Token** - POST `/api/oauth/refresh-token` with refresh_token
4. **Get New Token** - Update ACCESS_TOKEN variable
5. **Verify** - GET `/api/oauth/me` with new token

### Scenario 3: Long-lived Integration

1. **Get Token** - Use initial credentials
2. **Create Personal Token** - POST `/api/oauth/tokens/personal`
3. **Save Token** - This token never expires (6 months)
4. **Use for Integration** - Use this token in scripts/services

---

## Common Issues

### Issue: "Invalid client credentials"
**Solution:** Verify client_id and client_secret are correct

### Issue: "The user credentials were incorrect"
**Solution:** Check user_id and password in database

### Issue: "Unauthorized" on protected routes
**Solution:** 
1. Verify ACCESS_TOKEN is set
2. Check token hasn't expired
3. Use refresh token to get new token

### Issue: "Invalid refresh token"
**Solution:** Refresh token may have expired (30 days default). Re-authenticate.

---

## Automated Testing Script

### PowerShell
```powershell
# Get token
$response = Invoke-WebRequest -Uri "http://localhost:8000/api/oauth/issue-token" `
  -Method POST `
  -ContentType "application/json" `
  -Body '{"user_id":"admin001","password":"password123","scope":"*"}'

$token = ($response.Content | ConvertFrom-Json).access_token

# Use token
$headers = @{
  "Authorization" = "Bearer $token"
}

$user = Invoke-WebRequest -Uri "http://localhost:8000/api/oauth/me" `
  -Method GET `
  -Headers $headers

Write-Output $user.Content
```

### Bash
```bash
#!/bin/bash

API_URL="http://localhost:8000"

# Get token
TOKEN_RESPONSE=$(curl -s -X POST "$API_URL/api/oauth/issue-token" \
  -H "Content-Type: application/json" \
  -d '{"user_id":"admin001","password":"password123","scope":"*"}')

ACCESS_TOKEN=$(echo $TOKEN_RESPONSE | jq -r '.access_token')

echo "Access Token: $ACCESS_TOKEN"

# Get user
curl -s -X GET "$API_URL/api/oauth/me" \
  -H "Authorization: Bearer $ACCESS_TOKEN" | jq .
```

### Python
```python
import requests
import json

API_URL = "http://localhost:8000"

# Get token
token_response = requests.post(
    f"{API_URL}/api/oauth/issue-token",
    json={
        "user_id": "admin001",
        "password": "password123",
        "scope": "*"
    }
)

data = token_response.json()
access_token = data['access_token']

print(f"Access Token: {access_token}")

# Get user
headers = {"Authorization": f"Bearer {access_token}"}
user_response = requests.get(f"{API_URL}/api/oauth/me", headers=headers)

print(json.dumps(user_response.json(), indent=2))
```

---

## Performance Testing

### Load Testing with Apache Bench
```bash
# Generate token first
TOKEN="your_access_token"

# Test endpoint
ab -n 1000 -c 10 -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/oauth/me
```

### Using Locust
```python
from locust import HttpUser, task, between

class APIUser(HttpUser):
    wait_time = between(1, 3)
    
    def on_start(self):
        # Get token
        response = self.client.post("/api/oauth/issue-token", json={
            "user_id": "admin001",
            "password": "password123"
        })
        self.token = response.json()["access_token"]
    
    @task
    def get_user(self):
        headers = {"Authorization": f"Bearer {self.token}"}
        self.client.get("/api/oauth/me", headers=headers)
```

---

## Security Testing

### Test 1: Invalid Token
```bash
curl -X GET http://localhost:8000/api/oauth/me \
  -H "Authorization: Bearer invalid_token"
# Should return 401
```

### Test 2: Expired Token
```bash
# Use very old refresh token
curl -X POST http://localhost:8000/api/oauth/refresh-token \
  -H "Content-Type: application/json" \
  -d '{"refresh_token":"old_token"}'
# Should return error
```

### Test 3: CORS Requests
```javascript
// From browser console
fetch('http://localhost:8000/api/oauth/issue-token', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    user_id: 'admin001',
    password: 'password123'
  })
})
.then(r => r.json())
.then(d => console.log(d))
```

---

**Testing Complete!** ✅

For more details, see `PASSPORT_OAUTH2_GUIDE.md`
