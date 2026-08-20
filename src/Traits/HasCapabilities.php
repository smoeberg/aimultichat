<?php

declare(strict_types=1);

namespace Traits;

use Core\Security;

/**
 * Trait for controllers to easily check capabilities
 */
trait HasCapabilities
{
    /**
     * Check if current user has a capability
     */
    protected function can(string $capability): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        
        return $user->hasCapability($capability);
    }

    /**
     * Check capability or fail with 403
     */
    protected function canOrFail(string $capability, string $message = null): void
    {
        if (!$this->can($capability)) {
            Security::requireAuth();
            http_response_code(403);
            echo json_encode(['error' => $message ?? 'Du har ikke tilladelse til denne handling.']);
            exit;
        }
    }

    /**
     * Check capability or return JSON error
     */
    protected function canOrJson(string $capability, string $message = null): ?array
    {
        if (!$this->can($capability)) {
            return [
                'success' => false,
                'error' => $message ?? 'Du har ikke tilladelse til denne handling.',
                'capability_required' => $capability,
            ];
        }
        return null;
    }

    /**
     * Check if current user has a role
     */
    protected function isRole(string $roleCode): bool
    {
        $user = $this->getCurrentUser();
        if (!$user) return false;
        
        return $user->hasRole($roleCode);
    }

    /**
     * Check role or fail with 403
     */
    protected function isRoleOrFail(string $roleCode, string $message = null): void
    {
        if (!$this->isRole($roleCode)) {
            Security::requireAuth();
            http_response_code(403);
            echo json_encode(['error' => $message ?? 'Du har ikke tilladelse til denne handling.']);
            exit;
        }
    }

    /**
     * Get current user from session
     */
    protected function getCurrentUser()
    {
        if (isset($_SESSION['uid'])) {
            return \Models\User::findById((int)$_SESSION['uid']);
        }
        return null;
    }

    /**
     * Get current organization ID from session
     */
    protected function getCurrentOrganizationId(): ?int
    {
        return $_SESSION['current_organization_id'] ?? null;
    }
}
