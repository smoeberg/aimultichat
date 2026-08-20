<?php

declare(strict_types=1);

namespace Http\Controllers;

use Models\Organization;
use Models\OrganizationMember;
use Models\Role;
use Models\User;
use Traits\HasCapabilities;
use Core\Security;

/**
 * Controller for organization management
 */
final class OrganizationController
{
    use HasCapabilities;

    /**
     * Create a new organization
     * First user becomes owner
     */
    public function create(array $input): array
    {
        Security::requireCsrfHeader();
        
        $user = $this->getCurrentUser();
        if (!$user) {
            return ['error' => 'Ikke autoriseret', 'success' => false];
        }

        if (empty($input['name'])) {
            return ['error' => 'Organisationsnavn er påkrævet', 'success' => false];
        }

        // Create organization
        $organization = Organization::create($input['name'], $user->id);

        // First user = owner
        $ownerRole = Role::findByCode('owner');
        if (!$ownerRole) {
            // Fallback: create owner role if it doesn't exist
            $db = \Core\Database::getConnection();
            $db->exec("INSERT INTO roles (code, name, is_system) VALUES ('owner', 'Ejer', 1) ON DUPLICATE KEY UPDATE name = VALUES(name)");
            $ownerRole = Role::findByCode('owner');
        }
        
        OrganizationMember::create($organization->id, $user->id, $ownerRole->id);

        // Set as current organization
        $_SESSION['current_organization_id'] = $organization->id;

        return [
            'success' => true,
            'organization' => $organization->toArray(),
            'role' => 'owner',
        ];
    }

    /**
     * List all organizations for current user
     */
    public function list(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return ['error' => 'Ikke autoriseret', 'success' => false];
        }

        $organizations = $user->organizations();
        
        return [
            'success' => true,
            'organizations' => array_map(function($org) use ($user) {
                $member = OrganizationMember::findByOrganizationAndUser($org->id, $user->id);
                return [
                    'id' => $org->id,
                    'name' => $org->name,
                    'role' => $member?->role()?->code ?? 'unknown',
                    'is_current' => ($org->id === $this->getCurrentOrganizationId()),
                ];
            }, $organizations),
        ];
    }

    /**
     * Switch to a different organization
     */
    public function switchOrganization(array $input): array
    {
        Security::requireCsrfHeader();
        
        $user = $this->getCurrentUser();
        if (!$user) {
            return ['error' => 'Ikke autoriseret', 'success' => false];
        }

        if (empty($input['organization_id'])) {
            return ['error' => 'Organisations-ID er påkrævet', 'success' => false];
        }

        $organizationId = (int)$input['organization_id'];
        
        // Check if user is member of this organization
        $member = OrganizationMember::findByOrganizationAndUser($organizationId, $user->id);
        if (!$member) {
            return ['error' => 'Du er ikke medlem af denne organisation', 'success' => false];
        }

        // Switch organization
        $_SESSION['current_organization_id'] = $organizationId;

        return [
            'success' => true,
            'organization_id' => $organizationId,
            'role' => $member->role()?->code,
        ];
    }

    /**
     * Get current organization info
     */
    public function current(): array
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            return ['error' => 'Ikke autoriseret', 'success' => false];
        }

        $orgId = $this->getCurrentOrganizationId();
        if (!$orgId) {
            return ['error' => 'Ingen organisation valgt', 'success' => false];
        }

        $organization = Organization::findById($orgId);
        if (!$organization) {
            return ['error' => 'Organisation ikke fundet', 'success' => false];
        }

        $member = OrganizationMember::findByOrganizationAndUser($orgId, $user->id);
        
        return [
            'success' => true,
            'organization' => $organization->toArray(),
            'role' => $member?->role()?->toArray(),
            'capabilities' => $user->getCapabilities($orgId),
        ];
    }
}
