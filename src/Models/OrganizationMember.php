<?php

declare(strict_types=1);

namespace Models;

use Core\Database;

/**
 * OrganizationMember Model
 * Represents a user's membership in an organization with a specific role
 */
final class OrganizationMember
{
    public int $id;
    public int $organization_id;
    public int $user_id;
    public int $role_id;
    public string $created_at;
    public string $updated_at;

    /**
     * Find member by ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organization_members WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }

    /**
     * Find member by organization and user
     */
    public static function findByOrganizationAndUser(int $organizationId, int $userId): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM organization_members 
             WHERE organization_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$organizationId, $userId]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }

    /**
     * Get all members for an organization
     * @return array<OrganizationMember>
     */
    public static function findByOrganization(int $organizationId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organization_members WHERE organization_id = ?");
        $stmt->execute([$organizationId]);
        
        $members = [];
        while ($data = $stmt->fetch()) {
            $members[] = self::fromArray($data);
        }
        
        return $members;
    }

    /**
     * Get all members for a user
     * @return array<OrganizationMember>
     */
    public static function findByUser(int $userId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organization_members WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        $members = [];
        while ($data = $stmt->fetch()) {
            $members[] = self::fromArray($data);
        }
        
        return $members;
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        $member = new self();
        $member->id = (int)$data['id'];
        $member->organization_id = (int)$data['organization_id'];
        $member->user_id = (int)$data['user_id'];
        $member->role_id = (int)$data['role_id'];
        $member->created_at = $data['created_at'];
        $member->updated_at = $data['updated_at'];
        return $member;
    }

    /**
     * Create a new organization member
     */
    public static function create(int $organizationId, int $userId, int $roleId): self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO organization_members (organization_id, user_id, role_id, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([$organizationId, $userId, $roleId]);
        
        return self::findById((int)$db->lastInsertId());
    }

    /**
     * Get organization
     */
    public function organization(): ?Organization
    {
        return Organization::findById($this->organization_id);
    }

    /**
     * Get user
     */
    public function user(): ?User
    {
        return User::findById($this->user_id);
    }

    /**
     * Get role
     */
    public function role(): ?Role
    {
        return Role::findById($this->role_id);
    }

    /**
     * Update role
     */
    public function updateRole(int $newRoleId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "UPDATE organization_members SET role_id = ?, updated_at = NOW() WHERE id = ?"
        );
        return $stmt->execute([$newRoleId, $this->id]);
    }

    /**
     * Delete member
     */
    public function delete(): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM organization_members WHERE id = ?");
        return $stmt->execute([$this->id]);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'role_id' => $this->role_id,
            'role' => $this->role()?->toArray(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
