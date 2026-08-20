<?php

declare(strict_types=1);

namespace Helpers;

use Models\User;

/**
 * Role Helper Functions
 * Global helper functions for role and capability checks
 */

if (!function_exists('Helpers\can')) {
    /**
     * Check if current user has a capability
     */
    function can(string $capability): bool
    {
        $user = getCurrentUser();
        return $user?->hasCapability($capability) ?? false;
    }
}

if (!function_exists('Helpers\canOrFail')) {
    /**
     * Check capability or fail with 403
     */
    function canOrFail(string $capability, string $message = null): void
    {
        if (!can($capability)) {
            http_response_code(403);
            echo json_encode(['error' => $message ?? 'Du har ikke tilladelse til denne handling.']);
            exit;
        }
    }
}

if (!function_exists('Helpers\currentRole')) {
    /**
     * Get current user's role code
     */
    function currentRole(): ?string
    {
        $user = getCurrentUser();
        $role = $user?->getCurrentOrganizationRole();
        return $role?->code;
    }
}

if (!function_exists('Helpers\isRole')) {
    /**
     * Check if current user has a specific role
     */
    function isRole(string $roleCode): bool
    {
        $user = getCurrentUser();
        return $user?->hasRole($roleCode) ?? false;
    }
}

if (!function_exists('Helpers\isAnyRole')) {
    /**
     * Check if current user has any of the specified roles
     */
    function isAnyRole(array $roleCodes): bool
    {
        $user = getCurrentUser();
        return $user?->hasAnyRole($roleCodes) ?? false;
    }
}

if (!function_exists('Helpers\getCurrentUser')) {
    /**
     * Get current user from session
     */
    function getCurrentUser(): ?User
    {
        if (isset($_SESSION['uid'])) {
            return User::findById((int)$_SESSION['uid']);
        }
        return null;
    }
}

if (!function_exists('Helpers\getCurrentOrganizationId')) {
    /**
     * Get current organization ID from session
     */
    function getCurrentOrganizationId(): ?int
    {
        return $_SESSION['current_organization_id'] ?? null;
    }
}
