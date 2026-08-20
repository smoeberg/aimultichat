<?php

declare(strict_types=1);

namespace Models;

use Core\Database;

/**
 * Capability Model
 * Represents a permission/capability that can be assigned to roles
 */
final class Capability
{
    public int $id;
    public string $code;
    public ?string $description;
    public string $created_at;
    public string $updated_at;

    /**
     * Find capability by ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM capabilities WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }

    /**
     * Find capability by code
     */
    public static function findByCode(string $code): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM capabilities WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }

    /**
     * Get all capabilities
     * @return array<Capability>
     */
    public static function findAll(): array
    {
        $db = Database::getConnection();
        $result = $db->query("SELECT * FROM capabilities ORDER BY code");
        $capabilities = [];
        
        while ($data = $result->fetch()) {
            $capabilities[] = self::fromArray($data);
        }
        
        return $capabilities;
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        $capability = new self();
        $capability->id = (int)$data['id'];
        $capability->code = $data['code'];
        $capability->description = $data['description'] ?? null;
        $capability->created_at = $data['created_at'];
        $capability->updated_at = $data['updated_at'];
        return $capability;
    }

    /**
     * Get roles that have this capability
     * @return array<Role>
     */
    public function roles(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT r.* FROM roles r 
             JOIN role_capabilities rc ON r.id = rc.role_id 
             WHERE rc.capability_id = ?"
        );
        $stmt->execute([$this->id]);
        
        $roles = [];
        while ($data = $stmt->fetch()) {
            $roles[] = Role::fromArray($data);
        }
        
        return $roles;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'description' => $this->description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
