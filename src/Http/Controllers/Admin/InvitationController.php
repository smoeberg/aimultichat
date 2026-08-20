<?php

declare(strict_types=1);

namespace Http\Controllers\Admin;

use Models\Organization;
use Models\OrganizationMember;
use Models\Role;
use Models\User;
use Traits\HasCapabilities;
use Core\Security;
use Core\Database;

/**
 * Controller for managing invitations
 */
final class InvitationController
{
    use HasCapabilities;

    /**
     * Invite a user to the organization
     */
    public function invite(array $input): array
    {
        Security::requireCsrfHeader();
        
        $this->canOrFail('admin.users');
        
        $user = $this->getCurrentUser();
        if (!$user) {
            return ['error' => 'Ikke autoriseret', 'success' => false];
        }

        $organizationId = $this->getCurrentOrganizationId();
        if (!$organizationId) {
            return ['error' => 'Ingen organisation valgt', 'success' => false];
        }

        if (empty($input['email'])) {
            return ['error' => 'Email er påkrævet', 'success' => false];
        }

        if (empty($input['role_code'])) {
            return ['error' => 'Rolle er påkrævet', 'success' => false];
        }

        // Validate role
        $role = Role::findByCode($input['role_code']);
        if (!$role) {
            return ['error' => 'Ugyldig rolle', 'success' => false];
        }

        // Check if user already exists
        $existingUser = User::findByUsername($input['email']);
        if (!$existingUser) {
            $existingUser = User::findById((int)$input['email']);
        }
        
        if ($existingUser) {
            // Check if already member
            $exists = OrganizationMember::findByOrganizationAndUser($organizationId, $existingUser->id);
            if ($exists) {
                return ['error' => 'Brugeren er allerede medlem af organisationen.', 'success' => false];
            }
        }

        // Create invitation
        $db = Database::getConnection();
        $token = bin2hex(random_bytes(32));
        
        $db->exec(
            "INSERT INTO invitations (organization_id, email, role_id, token, expires_at, created_at, updated_at) 
             VALUES ({$organizationId}, '{$db->quote($input['email'])}', {$role->id}, '{$token}', DATE_ADD(NOW(), INTERVAL 7 DAY), NOW(), NOW())"
        );

        // Note: Email sending would be implemented here
        // For now, just return the invitation token for testing

        return [
            'success' => true,
            'message' => "Invitation oprettet til {$input['email']} med rollen {$role->name}",
            'invitation_token' => $token, // For testing purposes
        ];
    }

    /**
     * List all pending invitations for the organization
     */
    public function listInvitations(): array
    {
        $this->canOrFail('admin.users');
        
        $user = $this->getCurrentUser();
        if (!$user) {
            return ['error' => 'Ikke autoriseret', 'success' => false];
        }

        $organizationId = $this->getCurrentOrganizationId();
        if (!$organizationId) {
            return ['error' => 'Ingen organisation valgt', 'success' => false];
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT i.*, r.code as role_code, r.name as role_name 
             FROM invitations i 
             JOIN roles r ON i.role_id = r.id 
             WHERE i.organization_id = ? 
             ORDER BY i.created_at DESC"
        );
        $stmt->execute([$organizationId]);
        
        $invitations = [];
        while ($row = $stmt->fetch()) {
            $invitations[] = [
                'id' => (int)$row['id'],
                'email' => $row['email'],
                'role_code' => $row['role_code'],
                'role_name' => $row['role_name'],
                'token' => $row['token'],
                'expires_at' => $row['expires_at'],
                'created_at' => $row['created_at'],
            ];
        }

        return [
            'success' => true,
            'invitations' => $invitations,
        ];
    }

    /**
     * Revoke an invitation
     */
    public function revoke(array $input): array
    {
        Security::requireCsrfHeader();
        
        $this->canOrFail('admin.users');
        
        if (empty($input['invitation_id'])) {
            return ['error' => 'Invitations-ID er påkrævet', 'success' => false];
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM invitations WHERE id = ?");
        $deleted = $stmt->execute([(int)$input['invitation_id']]);

        if ($deleted) {
            return ['success' => true, 'message' => 'Invitationen er blevet trukket tilbage.'];
        }

        return ['error' => 'Invitationen blev ikke fundet', 'success' => false];
    }
}
