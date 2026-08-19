<?php
declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Security;
use PDO;

final class Chat 
{
    public int $id = 0;
    public int $userId = 0;
    public string $title = '';

    /**
     * Finder en chat ud fra dens ID.
     */
    public static function findById(int $id): ?self 
    { 
        $stmt = Database::getInstance()->prepare(
            'SELECT id, user_id, title FROM conversations WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]); 
        $data = $stmt->fetch(PDO::FETCH_ASSOC); 
        
        return $data ? self::from($data) : null; 
    }

    /**
     * Opretter en ny samtale for en bruger.
     */
    public static function create(int $userId, string $title = 'Ny chat'): self
    {
        $db = Database::getInstance();
        $title = trim($title) !== '' ? mb_substr(trim($title), 0, 80) : 'Ny chat';

        $stmt = $db->prepare('INSERT INTO conversations (user_id, title) VALUES (?, ?)');
        $stmt->execute([$userId, Security::encryptIfConfigured($title)]);

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

        return $c;
    }

    /**
     * Henter chattens titel sikkert. 
     * Hvis titlen er krypteret, forsøges dekryptering uden at kaste exceptions ved fejl.
     */
    public function getTitle(): string
    {
        return self::decodeTitle($this->title);
    }

    public static function decodeTitle(string $title): string
    {
        if ($title === '') {
            return 'Ny chat';
        }
        return Security::decryptOrPlaintext($title) ?: 'Ny chat';
    }

    /**
     * Henter alle beskeder tilhørende denne chat.
     */
    public function getMessages(int $limit = 100): array 
    { 
        return Message::forChat($this->id, $limit); 
    }

    public function addExchange(
        string $userContent,
        string $assistantContent,
        ?int $botId,
        ?string $newTitle = null
    ): void {
        $database = Database::getInstance();
        $database->beginTransaction();
        try {
            $insert = $database->prepare(
                'INSERT INTO messages(conversation_id, role, content, bot_id) VALUES(?, ?, ?, ?)'
            );
            $insert->execute([
                $this->id,
                'user',
                Security::encryptIfConfigured($userContent),
                null,
            ]);
            $insert->execute([
                $this->id,
                'assistant',
                Security::encryptIfConfigured($assistantContent),
                $botId,
            ]);

            if ($newTitle !== null && trim($newTitle) !== '') {
                $cleanTitle = mb_substr(trim($newTitle), 0, 80);
                $storedTitle = Security::encryptIfConfigured($cleanTitle);
                $database->prepare(
                    'UPDATE conversations SET title = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute([$storedTitle, $this->id]);
                $this->title = $storedTitle;
            } else {
                $database->prepare(
                    'UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute([$this->id]);
            }
            $database->commit();
        } catch (\Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Sletter chatten samt tilhørende beskeder.
     */
    public function delete(): void
    {
        Database::getInstance()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$this->id]);
    }

}
