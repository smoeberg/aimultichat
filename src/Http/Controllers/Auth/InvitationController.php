<?php

declare(strict_types=1);

namespace Http\Controllers\Auth;

use Models\Organization;
use Models\OrganizationMember;
use Models\Role;
use Models\User;
use Core\Database;
use Core\Security;

/**
 * Controller for accepting invitations
 */
final class InvitationController
{
    /**
     * Accept an invitation and join the organization
     */
    public function accept(string $token): array
    {
        $db = Database::getConnection();
        
        // Find invitation
        $stmt = $db->prepare(
            "SELECT * FROM invitations 
             WHERE token = ? AND expires_at > NOW() 
             LIMIT 1"
        );
        $stmt->execute([$token]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            return ['error' => 'Invitationen er ugyldig eller udløbet.', 'success' => false];
        }

        // Get current user
        $user = $this->getCurrentUser();
        
        if (!$user) {
            // Store token in session for after login
            $_SESSION['pending_invitation_token'] = $token;
            return [
                'error' => 'Du skal være logget ind for at acceptere invitationen.',
                'success' => false,
                'redirect' => '/login',
            ];
        }

        // Check if email matches
        if ($user->username !== $invitation['email'] && $user->id != $invitation['email']) {
            return ['error' => 'Invitationen er sendt til en anden email-adresse.', 'success' => false];
        }

        // Check if user is already member
        $exists = OrganizationMember::findByOrganizationAndUser(
            (int)$invitation['organization_id'],
            $user->id
        );

        if ($exists) {
            return [
                'success' => true,
                'message' => 'Du er allerede medlem af organisationen.',
                'redirect' => '/',
            ];
        }

        // Create membership
        OrganizationMember::create(
            (int)$invitation['organization_id'],
            $user->id,
            (int)$invitation['role_id']
        );

        // Delete invitation
        $stmt = $db->prepare("DELETE FROM invitations WHERE token = ?");
        $stmt->execute([$token]);

        // Set as current organization
        $_SESSION['current_organization_id'] = (int)$invitation['organization_id'];

        // Clear pending invitation token
        unset($_SESSION['pending_invitation_token']);

        return [
            'success' => true,
            'message' => 'Du er nu medlem af organisationen.',
            'organization_id' => (int)$invitation['organization_id'],
            'redirect' => '/',
        ];
    }

    /**
     * Check if there's a pending invitation after login
     */
    public function checkPending(): array
    {
        if (!isset($_SESSION['pending_invitation_token'])) {
            return ['success' => true, 'pending' => false];
        }

        $token = $_SESSION['pending_invitation_token'];
        $db = Database::getConnection();
        
        $stmt = $db->prepare("SELECT * FROM invitations WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $invitation = $stmt->fetch();

        if (!$invitation) {
            unset($_SESSION['pending_invitation_token']);
            return ['success' => true, 'pending' => false];
        }

        return [
            'success' => true,
            'pending' => true,
            'invitation' => [
                'email' => $invitation['email'],
                'organization_id' => (int)$invitation['organization_id'],
                'role_id' => (int)$invitation['role_id'],
            ],
        ];
    }

    /**
     * Get current user from session
     */
    private function getCurrentUser(): ?User
    {
        if (isset($_SESSION['uid'])) {
            return User::findById((int)$_SESSION['uid']);
        }
        return null;
    }
}
