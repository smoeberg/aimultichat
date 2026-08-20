<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use UnexpectedValueException;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EntraTokenValidator
{
    protected string $tenantId;
    protected string $clientId;
    protected string $jwksUri;

    public function __construct()
    {
        $this->tenantId = config('services.entra.tenant_id', env('ENTRA_TENANT_ID'));
        $this->clientId = config('services.entra.client_id', env('ENTRA_CLIENT_ID'));
        $this->jwksUri = "https://login.microsoftonline.com/{$this->tenantId}/discovery/v2.0/keys";
    }

    /**
     * Validate Entra ID token with robust JWKS caching, kid rotation retry, and tenant check.
     */
    public function validate(string $jwtToken): object
    {
        try {
            $jwks = $this->getJwks();
            $decoded = JWT::decode($jwtToken, JWK::parseKeySet($jwks));
        } catch (Exception $e) {
            // Check if failure might be due to Key Rotation (kid mismatch)
            Log::warning("Entra token decode failed, attempting JWKS cache refresh: " . $e->getMessage());
            $jwks = $this->getJwks(true); // force refresh
            try {
                $decoded = JWT::decode($jwtToken, JWK::parseKeySet($jwks));
            } catch (Exception $nestedEx) {
                Log::error("Entra token validation failed after JWKS refresh: " . $nestedEx->getMessage());
                throw new UnexpectedValueException("Invalid authentication token.");
            }
        }

        // 1. Verify Audience (Client ID)
        if ($decoded->aud !== $this->clientId) {
            Log::warning("Entra token invalid audience: {$decoded->aud}");
            throw new UnexpectedValueException("Invalid token audience.");
        }

        // 2. Verify Issuer (Supports both standard Microsoft issuer formats)
        $expectedIss1 = "https://login.microsoftonline.com/{$this->tenantId}/v2.0";
        $expectedIss2 = "https://sts.windows.net/{$this->tenantId}/";
        
        if ($decoded->iss !== $expectedIss1 && $decoded->iss !== $expectedIss2) {
            Log::warning("Entra token invalid issuer: {$decoded->iss}");
            throw new UnexpectedValueException("Invalid token issuer.");
        }

        // 3. Verify Tenant ID (tid) for single-tenant or strict multi-tenant
        $tokenTid = $decoded->tid ?? null;
        if ($tokenTid !== $this->tenantId) {
            Log::warning("Entra token tenant mismatch. Expected: {$this->tenantId}, Got: " . ($tokenTid ?? 'null'));
            throw new UnexpectedValueException("Invalid token tenant.");
        }

        return $decoded;
    }

    protected function getJwks(bool $forceRefresh = false): array
    {
        $cacheKey = 'entra_jwks_' . $this->tenantId;

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addHours(12), function () {
            $json = @file_get_contents($this->jwksUri);
            if ($json === false) {
                throw new Exception("Failed to fetch JWKS from Entra discovery endpoint.");
            }
            $data = json_decode($json, true);
            if (!isset($data['keys'])) {
                throw new Exception("Invalid JWKS payload received.");
            }
            return $data;
        });
    }
}
