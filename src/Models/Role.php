<?php

declare(strict_types=1);

namespace Models;

use Core\Database;

/**
 * Role Model
 * Represents a user role with capabilities
 */
final class Role
{
    public int $id;
    public string $code;
    public string $name;
    public bool $is_system;
    public string $created_at;
    public string $updated_at;

    /**
     * Find role by ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM roles WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }

    /**
     * Find role by code
     */
    public static function findByCode(string $code): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM roles WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }

    /**
     * Get all roles
     * @return array<Role>
     */
    public static function findAll(): array
    {
        $db = Database::getConnection();
        $result = $db->query("SELECT * FROM roles ORDER BY code");
        $roles = [];
        
        while ($data = $result->fetch()) {
            $roles[] = self::fromArray($data);
        }
        
        return $roles;
    }

    /**
     * Create from array
     */
    private static function fromArray(array $data): self
    {
        $role = new self();
        $role->id = (int)$data['id'];
        $role->code = $data['code'];
        $role->name = $data['name'];
        $role->is_system = (bool)$data['is_system'];
        $role->created_at = $data['created_at'];
        $role->updated_at = $data['updated_at'];
        return $role;
    }

    /**
     * Get capabilities for this role
     * @return array<Capability>
     */
    public function capabilities(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT c.* FROM capabilities c 
             JOIN role_capabilities rc ON c.id = rc.capability_id 
             WHERE rc.role_id = ?"
        );
        $stmt->execute([$this->id]);
        
        $capabilities = [];
        while ($data = $stmt->fetch()) {
            $capabilities[] = Capability::fromArray($data);
        }
        
        return $capabilities;
    }

    /**
     * Get capability codes for this role
     * @return array<string>
     */
    public function capabilityCodes(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT c.code FROM capabilities c 
             JOIN role_capabilities rc ON c.id = rc.capability_id 
             WHERE rc.role_id = ?"
        );
        $stmt->execute([$this->id]);
        
        $codes = [];
        while ($row = $stmt->fetch()) {
            $codes[] = $row['code'];
        }
        
        return $codes;
    }

    /**
     * Check if role has a specific capability
     */
    public function hasCapability(string $capabilityCode): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM role_capabilities rc 
             JOIN capabilities c ON rc.capability_id = c.id 
             WHERE rc.role_id = ? AND c.code = ?"
        );
        $stmt->execute([$this->id, $capabilityCode]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get organization members with this role
     * @return array<OrganizationMember>
     */
    public function members(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organization_members WHERE role_id = ?");
        $stmt->execute([$this->id]);
        
        $members = [];
        while ($data = $stmt->fetch()) {
            $members[] = OrganizationMember::fromArray($data);
        }
        
        return $members;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_system' => $this->is_system,
            'capabilities' => $this->capabilityCodes(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
