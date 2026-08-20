<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\AuthIdentity;
use Illuminate\Support\Facades\Log;
use Exception;

class EntraUserResolver
{
    /**
     * Resolve user from Entra ID claims without auto-provisioning in pilot.
     */
    public function resolve(object $claims): User
    {
        $oid = $claims->oid ?? $claims->sub ?? null;
        $email = $claims->email ?? $claims->preferred_username ?? null;
        $name = $claims->name ?? 'Entra User';

        if (!$oid) {
            throw new Exception("Missing 'oid' or 'sub' claim in token.");
        }

        // 1. Look up via auth_identities (primary secure link)
        $identity = AuthIdentity::where('provider', 'entra')
            ->where('provider_user_id', $oid)
            ->with('user')
            ->first();

        if ($identity && $identity->user) {
            // Update last login & safe claims snapshot
            $identity->update([
                'last_login_at' => now(),
                'raw_claims' => $this->sanitizeClaims($claims),
            ]);
            return $identity->user;
        }

        // 2. Fallback check by email (strict secure matching)
        if ($email) {
            $user = User::where('email', $email)->first();
            if ($user) {
                // Check if user already has a different entra identity bound
                $existingIdentity = AuthIdentity::where('user_id', $user->id)
                    ->where('provider', 'entra')
                    ->first();

                if ($existingIdentity && $existingIdentity->provider_user_id !== $oid) {
                    Log::error("Security collision: Email {$email} is bound to another Entra OID.");
                    throw new Exception("Account conflict detected. Contact administrator.");
                }

                // Bind this OID to the existing user
                AuthIdentity::create([
                    'user_id' => $user->id,
                    'provider' => 'entra',
                    'provider_user_id' => $oid,
                    'last_login_at' => now(),
                    'raw_claims' => $this->sanitizeClaims($claims),
                ]);

                return $user;
            }
        }

        // Pilot policy: No auto-creation of new users
        Log::warning("Entra login rejected: User not found for OID {$oid} and email " . ($email ?? 'unknown'));
        throw new Exception("User account not found. Automatic provisioning is disabled in this pilot.");
    }

    protected function sanitizeClaims(object $claims): array
    {
        // Strip out tokens or heavy fields, keep non-sensitive profile claims
        return [
            'oid' => $claims->oid ?? null,
            'sub' => $claims->sub ?? null,
            'email' => $claims->email ?? $claims->preferred_username ?? null,
            'name' => $claims->name ?? null,
            'tid' => $claims->tid ?? null,
        ];
    }
}
