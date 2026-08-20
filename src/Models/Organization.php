<?php

declare(strict_types=1);

namespace Models;

use Core\Database;

/**
 * Organization Model
 * Represents a customer organization
 */
final class Organization
{
    public int $id;
    public string $name;
    public int $owner_id;
    public string $created_at;
    public string $updated_at;

    /**
     * Find organization by ID
     */
    public static function findById(int $id): ?self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        
        if (!$data) {
            return null;
        }
        
        return self::fromArray($data);
    }

    /**
     * Find organization by owner
     * @return array<Organization>
     */
    public static function findByOwner(int $ownerId): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organizations WHERE owner_id = ?");
        $stmt->execute([$ownerId]);
        
        $organizations = [];
        while ($data = $stmt->fetch()) {
            $organizations[] = self::fromArray($data);
        }
        
        return $organizations;
    }

    /**
     * Get all organizations
     * @return array<Organization>
     */
    public static function findAll(): array
    {
        $db = Database::getConnection();
        $result = $db->query("SELECT * FROM organizations ORDER BY name");
        $organizations = [];
        
        while ($data = $result->fetch()) {
            $organizations[] = self::fromArray($data);
        }
        
        return $organizations;
    }

    /**
     * Create from array
     */
    private static function fromArray(array $data): self
    {
        $org = new self();
        $org->id = (int)$data['id'];
        $org->name = $data['name'];
        $org->owner_id = (int)$data['owner_id'];
        $org->created_at = $data['created_at'];
        $org->updated_at = $data['updated_at'];
        return $org;
    }

    /**
     * Create a new organization
     */
    public static function create(string $name, int $ownerId): self
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "INSERT INTO organizations (name, owner_id, created_at, updated_at) 
             VALUES (?, ?, NOW(), NOW())"
        );
        $stmt->execute([$name, $ownerId]);
        
        return self::findById((int)$db->lastInsertId());
    }

    /**
     * Get owner
     */
    public function owner(): ?User
    {
        return User::findById($this->owner_id);
    }

    /**
     * Get members
     * @return array<OrganizationMember>
     */
    public function members(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM organization_members WHERE organization_id = ?");
        $stmt->execute([$this->id]);
        
        $members = [];
        while ($data = $stmt->fetch()) {
            $members[] = OrganizationMember::fromArray($data);
        }
        
        return $members;
    }

    /**
     * Get user members with their roles
     * @return array<User>
     */
    public function users(): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT u.* FROM users u 
             JOIN organization_members om ON u.id = om.user_id 
             WHERE om.organization_id = ?"
        );
        $stmt->execute([$this->id]);
        
        $users = [];
        while ($data = $stmt->fetch()) {
            $users[] = User::fromArray($data);
        }
        
        return $users;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner_id' => $this->owner_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
