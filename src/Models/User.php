<?php
declare(strict_types=1);

namespace Models;

use Core\Database;
use PDO;

final class User
{
    public int $id;
    public string $name;
    public ?string $username;
    public ?string $passwordHash;
    public string $role;
    public bool $enabled;

    public static function findById(int $id): ?self
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM users WHERE id = ? AND enabled = 1');
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        return $data ? self::fromArray($data) : null;
    }

    public static function findByUsername(string $username): ?self
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT * FROM users WHERE username = ? AND enabled = 1 LIMIT 1'
        );
        $stmt->execute([$username]);
        $data = $stmt->fetch();
        return $data ? self::fromArray($data) : null;
    }

    public static function findAnyByUsername(string $username): ?self
    {
        $stmt = Database::getInstance()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $data = $stmt->fetch();
        return $data ? self::fromArray($data) : null;
    }

    public function getChats(): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, title, created_at, updated_at FROM conversations '
            . 'WHERE user_id = ? ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([$this->id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'title' => Chat::decodeTitle((string)($row['title'] ?? '')),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ], $rows);
    }

    public function createChat(string $title = 'Ny chat'): int
    {
        $chat = Chat::create($this->id, $title);
        return $chat->id;
    }

    private static function fromArray(array $data): self
    {
        $user = new self();
        $user->id = (int)$data['id'];
        $user->name = (string)$data['name'];
        $user->username = isset($data['username']) ? (string)$data['username'] : null;
        $user->passwordHash = isset($data['password_hash']) ? (string)$data['password_hash'] : null;
        $user->role = (string)$data['role'];
        $user->enabled = (bool)$data['enabled'];
        return $user;
    }
}
