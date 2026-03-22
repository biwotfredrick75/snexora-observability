<?php

namespace App\Clients;

use Illuminate\Support\Facades\Http;
use Exception;

/**
 * OAuth2 API Client
 * 
 * Usage Example:
 * $client = new ApiClient('https://api.example.com', 'client_id', 'client_secret');
 * $user = $client->authenticate('user_id', 'password')->getUser();
 */
class OAuth2ApiClient
{
    protected string $apiUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected ?string $accessToken = null;
    protected ?string $refreshToken = null;
    protected ?int $expiresAt = null;

    public function __construct(string $apiUrl, string $clientId, string $clientSecret)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    /**
     * Authenticate with user credentials
     * 
     * @param string $userId
     * @param string $password
     * @param string $scope
     * @return self
     * @throws Exception
     */
    public function authenticate(string $userId, string $password, string $scope = '*'): self
    {
        $response = Http::post(
            "{$this->apiUrl}/api/oauth/issue-token",
            [
                'user_id' => $userId,
                'password' => $password,
                'scope' => $scope,
            ]
        );

        if (!$response->successful()) {
            throw new Exception("Authentication failed: {$response->body()}");
        }

        $data = $response->json();
        $this->accessToken = $data['access_token'];
        $this->refreshToken = $data['refresh_token'] ?? null;
        $this->expiresAt = time() + ($data['expires_in'] ?? 3600);

        return $this;
    }

    /**
     * Check if token is expired
     */
    public function isTokenExpired(): bool
    {
        return $this->expiresAt && time() >= $this->expiresAt;
    }

    /**
     * Refresh the access token
     */
    public function refreshAccessToken(): self
    {
        if (!$this->refreshToken) {
            throw new Exception('No refresh token available. Re-authenticate first.');
        }

        $response = Http::post(
            "{$this->apiUrl}/api/oauth/refresh-token",
            ['refresh_token' => $this->refreshToken]
        );

        if (!$response->successful()) {
            throw new Exception("Token refresh failed: {$response->body()}");
        }

        $data = $response->json();
        $this->accessToken = $data['access_token'];
        $this->refreshToken = $data['refresh_token'] ?? $this->refreshToken;
        $this->expiresAt = time() + ($data['expires_in'] ?? 3600);

        return $this;
    }

    /**
     * Get current authenticated user
     */
    public function getUser(): array
    {
        return $this->get('oauth/me');
    }

    /**
     * Make authenticated GET request
     */
    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, [], $params);
    }

    /**
     * Make authenticated POST request
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Make authenticated PUT request
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * Make authenticated DELETE request
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint, []);
    }

    /**
     * Make authenticated HTTP request
     */
    protected function request(string $method, string $endpoint, array $data = [], array $params = []): array
    {
        // Auto-refresh token if expired
        if ($this->isTokenExpired()) {
            $this->refreshAccessToken();
        }

        $url = "{$this->apiUrl}/api/{$endpoint}";
        
        $response = Http::withToken($this->accessToken)
            ->{strtolower($method)}($url, $data);

        if (!$response->successful()) {
            throw new Exception("API request failed: {$response->body()}");
        }

        return $response->json();
    }

    /**
     * Get current access token
     */
    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * Set access token manually
     */
    public function setAccessToken(string $token, int $expiresIn = 3600): self
    {
        $this->accessToken = $token;
        $this->expiresAt = time() + $expiresIn;
        return $this;
    }

    /**
     * Logout and revoke all tokens
     */
    public function logout(): bool
    {
        try {
            $this->post('oauth/logout');
            $this->accessToken = null;
            $this->refreshToken = null;
            $this->expiresAt = null;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get user's tokens
     */
    public function listTokens(): array
    {
        return $this->get('oauth/tokens');
    }

    /**
     * Revoke a specific token
     */
    public function revokeToken(string $tokenId): bool
    {
        try {
            $this->post('oauth/tokens/revoke', ['token_id' => $tokenId]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create personal access token for long-lived integrations
     */
    public function createPersonalToken(string $tokenName, array $scopes = ['*']): array
    {
        return $this->post('oauth/tokens/personal', [
            'token_name' => $tokenName,
            'scopes' => $scopes,
        ]);
    }
}
