<?php
declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Security;
use PDO;
use Throwable;

final class Chat 
{
    public int $id = 0;
    public int $userId = 0;
    public string $title = '';
    public string $createdAt = '';
    public string $updatedAt = '';

    /**
     * Finder en chat ud fra dens ID.
     */
    public static function findById(int $id): ?self 
    { 
        $stmt = Database::getInstance()->prepare('SELECT * FROM conversations WHERE id = ? LIMIT 1'); 
        $stmt->execute([$id]); 
        $data = $stmt->fetch(PDO::FETCH_ASSOC); 
        
        return $data ? self::from($data) : null; 
    }

    /**
     * Henter alle samtaler for en bestemt bruger (sorteret efter senest opdateret).
     *
     * @return self[]
     */
    public static function findAllForUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare('
            SELECT * FROM conversations 
            WHERE user_id = ? 
            ORDER BY updated_at DESC, id DESC
        ');
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $chats = [];
        foreach ($rows as $row) {
            $chats[] = self::from($row);
        }

        return $chats;
    }

    /**
     * Alias for findAllForUser for bagudkompatibilitet.
     *
     * @return self[]
     */
    public static function forUser(int $userId): array
    {
        return self::findAllForUser($userId);
    }

    /**
     * Opretter en ny samtale for en bruger.
     */
    public static function create(int $userId, string $title = 'Ny chat'): self
    {
        $db = Database::getInstance();
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 80) : 'Ny chat';

        $stmt = $db->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)');
        $stmt->execute([$userId, $title]);

        $id = (int)$db->lastInsertId();
        $chat = self::findById($id);

        if (!$chat) {
            throw new \RuntimeException('Kunne ikke oprette ny chat.');
        }

        return $chat;
    }

    /**
     * Konstruerer et Chat-objekt ud fra et database-array.
     */
    private static function from(array $d): self 
    { 
        $c = new self();
        $c->id = (int)($d['id'] ?? 0);
        $c->userId = (int)($d['user_id'] ?? $d['userId'] ?? 0);
        $c->title = (string)($d['title'] ?? '');
        $c->createdAt = (string)($d['created_at'] ?? $d['createdAt'] ?? '');
        $c->updatedAt = (string)($d['updated_at'] ?? $d['updatedAt'] ?? '');
        
        return $c; 
    }

    /**
     * Henter chattens titel sikkert. 
     * Hvis titlen er krypteret, forsøges dekryptering uden at kaste exceptions ved fejl.
     */
    public function getTitle(): string
    {
        if ($this->title === '') {
            return 'Ny chat';
        }

        try {
            $res = Security::decryptWithStatus($this->title);
            if ($res['data'] !== null && $res['data'] !== '') {
                return $res['data'];
            }
        } catch (Throwable $e) {
            // Fejl i dekryptering ignoreres og falder tilbage på rå tekst
        }

        return $this->title;
    }

    /**
     * Henter alle beskeder tilhørende denne chat.
     */
    public function getMessages(int $limit = 100): array 
    { 
        return Message::forChat($this->id, $limit); 
    }

    /**
     * Tilføjer en besked til chatten og opdaterer oprettelsestidspunktet.
     */
    public function addMessage(string $role, string $content, ?int $botId = null): void 
    { 
        $db = Database::getInstance();
        $s = $db->prepare('INSERT INTO messages(conversation_id, role, content, bot_id) VALUES(?, ?, ?, ?)');
        $s->execute([$this->id, $role, $content, $botId]);
        
        $db->prepare('UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$this->id]); 
    }

    /**
     * Hjælpemetode til at tilføje systembeskeder.
     */
    public function addSystemMessage(string $content, ?int $botId = null): void 
    { 
        $this->addMessage('system', $content, $botId); 
    }

    /**
     * Henter ID på den senest benyttede bot i chatten.
     */
    public function getLastBotId(): ?int 
    { 
        $s = Database::getInstance()->prepare("
            SELECT bot_id 
            FROM messages 
            WHERE conversation_id = ? AND bot_id IS NOT NULL 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $s->execute([$this->id]);
        $v = $s->fetchColumn();
        
        return $v === false ? null : (int)$v; 
    }

    /**
     * Opdaterer titlen på chatten.
     */
    public function updateTitle(string $title): void 
    { 
        $title = trim($title); 
        if ($title === '') {
            $title = 'Ny chat';
        }
        
        $cleanTitle = mb_substr($title, 0, 80);
        
        $db = Database::getInstance();
        $db->prepare('UPDATE conversations SET title = ? WHERE id = ?')->execute([$cleanTitle, $this->id]);
        
        $this->title = $cleanTitle; 
    }

    /**
     * Sletter chatten samt tilhørende beskeder.
     */
    public function delete(): void
    {
        $db = Database::getInstance();
        $db->prepare('DELETE FROM messages WHERE conversation_id = ?')->execute([$this->id]);
        $db->prepare('DELETE FROM conversations WHERE id = ?')->execute([$this->id]);
    }

    /**
     * Magic getter for bagudkompatibilitet med snake_case egenskaber.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'user_id' => $this->userId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            default => null,
        };
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['user_id', 'created_at', 'updated_at'], true);
    }
}