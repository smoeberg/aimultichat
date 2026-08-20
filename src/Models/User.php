<?php
declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Security;
use PDO;

class User
{
    public int $id;
    public string $name;
    public ?string $username;
    public ?string $passwordHash;
    public string $role;
    public bool $enabled;
    public ?string $sessionToken;
    
    public static function findById(int $id): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND enabled = 1');
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    public static function findByUsername(string $username): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND enabled = 1');
        $stmt->execute([$username]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    public static function findBySessionToken(string $token): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT * FROM users WHERE session_token = ? AND enabled = 1');
        $stmt->execute([$token]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    public static function findGuestByToken(string $token): ?self
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT * FROM users WHERE session_token = ? AND (username IS NULL OR username = '') AND enabled = 1"
        );
        $stmt->execute([$token]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }
    
    public static function createGuest(): self
    {
        $db = Database::getInstance();
        $token = Security::generateSessionToken();
        
        $stmt = $db->prepare(
            'INSERT INTO users(name, session_token, role, enabled) VALUES(?, ?, "user", 1)'
        );
        $stmt->execute(['Guest', $token]);
        
        $user = new self();
        $user->id = (int)$db->lastInsertId();
        $user->name = 'Guest';
        $user->username = null;
        $user->passwordHash = null;
        $user->role = 'user';
        $user->enabled = true;
        $user->sessionToken = $token;
        
        return $user;
    }
    
    public static function createAdmin(string $username, string $password): self
    {
        $db = Database::getInstance();
        $token = Security::generateSessionToken();
        $hash = Security::hashPassword($password);
        
        $stmt = $db->prepare(
            'INSERT INTO users(name, username, password_hash, role, enabled, session_token) VALUES(?, ?, ?, "admin", 1, ?)'
        );
        $stmt->execute(['Administrator', $username, $hash, $token]);
        
        $user = new self();
        $user->id = (int)$db->lastInsertId();
        $user->name = 'Administrator';
        $user->username = $username;
        $user->passwordHash = $hash;
        $user->role = 'admin';
        $user->enabled = true;
        $user->sessionToken = $token;
        
        return $user;
    }
    
    public function updatePassword(string $newPassword): void
    {
        $db = Database::getInstance();
        $hash = Security::hashPassword($newPassword);
        
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $this->id]);
        
        $this->passwordHash = $hash;
    }
    
    public function updateSessionToken(): void
    {
        $db = Database::getInstance();
        $token = Security::generateSessionToken();
        
        $stmt = $db->prepare('UPDATE users SET session_token = ? WHERE id = ?');
        $stmt->execute([$token, $this->id]);
        
        $this->sessionToken = $token;
        $_SESSION['token'] = $token;
    }
    
    public function getChats(): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, title, created_at, updated_at FROM conversations WHERE user_id = ? ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([$this->id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $chats = [];
        foreach ($rows as $row) {
            $chat = Chat::findById((int)$row['id']);
            $title = $chat ? $chat->getTitle() : ($row['title'] ?? 'Ny chat');
            $chats[] = [
                'id' => (int)$row['id'],
                'title' => $title,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        return $chats;
    }
    
    public function createChat(string $title = 'Ny chat'): int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare('INSERT INTO conversations(user_id, title) VALUES(?, ?)');
        $stmt->execute([$this->id, $title]);
        return (int)$db->lastInsertId();
    }
    

    // ===== Organization & Role Methods =====

    /**
     * Get all organization memberships for this user
     * @return array<OrganizationMember>
     */
    public function organizationMembers(): array
    {
        return OrganizationMember::findByUser($this->id);
    }

    /**
     * Get all organizations this user belongs to
     * @return array<Organization>
     */
    public function organizations(): array
    {
        $members = $this->organizationMembers();
        $organizations = [];
        
        foreach ($members as $member) {
            $org = $member->organization();
            if ($org) {
                $organizations[] = $org;
            }
        }
        
        return $organizations;
    }

    /**
     * Get the user's role in a specific organization
     */
    public function getCurrentOrganizationRole(?int $organizationId = null): ?Role
    {
        $orgId = $organizationId ?? ($this->getCurrentOrganizationId());
        if (!$orgId) return null;

        $member = OrganizationMember::findByOrganizationAndUser($orgId, $this->id);
        if (!$member) return null;

        return $member->role();
    }

    /**
     * Get the current organization ID from session
     */
    public function getCurrentOrganizationId(): ?int
    {
        return $_SESSION['current_organization_id'] ?? null;
    }

    /**
     * Check if user has a specific capability in the current organization
     */
    public function hasCapability(string $capabilityCode, ?int $organizationId = null): bool
    {
        $role = $this->getCurrentOrganizationRole($organizationId);
        if (!$role) return false;

        return $role->hasCapability($capabilityCode);
    }

    /**
     * Check if user has any of the specified capabilities
     */
    public function hasAnyCapability(array $capabilityCodes, ?int $organizationId = null): bool
    {
        foreach ($capabilityCodes as $code) {
            if ($this->hasCapability($code, $organizationId)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the specified capabilities
     */
    public function hasAllCapabilities(array $capabilityCodes, ?int $organizationId = null): bool
    {
        foreach ($capabilityCodes as $code) {
            if (!$this->hasCapability($code, $organizationId)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if user has a specific role in the current organization
     */
    public function hasRole(string $roleCode, ?int $organizationId = null): bool
    {
        $role = $this->getCurrentOrganizationRole($organizationId);
        return $role && $role->code === $roleCode;
    }

    /**
     * Check if user has any of the specified roles
     */
    public function hasAnyRole(array $roleCodes, ?int $organizationId = null): bool
    {
        $role = $this->getCurrentOrganizationRole($organizationId);
        return $role && in_array($role->code, $roleCodes);
    }

    /**
     * Get all capability codes for the current organization
     * @return array<string>
     */
    public function getCapabilities(?int $organizationId = null): array
    {
        $role = $this->getCurrentOrganizationRole($organizationId);
        return $role ? $role->capabilityCodes() : [];
    }

    public function claimGuestHistory(User $guest): void
    {
        if ($this->id === $guest->id) {
            return;
        }
        
        $db = Database::getInstance();
        $db->beginTransaction();
        
        try {
            // Move conversations from guest to user
            $stmt = $db->prepare('UPDATE conversations SET user_id = ? WHERE user_id = ?');
            $stmt->execute([$this->id, $guest->id]);
            
            // Delete guest user
            $stmt = $db->prepare('DELETE FROM users WHERE id = ? AND (username IS NULL OR username = "")');
            $stmt->execute([$guest->id]);
            
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }
    
    private static function fromArray(array $data): self
    {
        $user = new self();
        $user->id = (int)$data['id'];
        $user->name = $data['name'];
        $user->username = $data['username'] ?? null;
        $user->passwordHash = $data['password_hash'] ?? null;
        $user->role = $data['role'];
        $user->enabled = (bool)$data['enabled'];
        $user->sessionToken = $data['session_token'] ?? null;
        
        return $user;
    }
}
