<?php
declare(strict_types=1);

namespace Models;

use Core\Database;
use Core\Security;

final class Message
{
    public static function forChat(int $chatId, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $statement = Database::getInstance()->prepare(
            "SELECT id, role, content, bot_id, created_at FROM messages "
            . "WHERE conversation_id = ? ORDER BY id DESC LIMIT {$limit}"
        );
        $statement->execute([$chatId]);
        $messages = array_reverse($statement->fetchAll());
        foreach ($messages as &$message) {
            $message['content'] = Security::decryptOrPlaintext($message['content'] ?? '');
        }
        unset($message);
        return $messages;
    }
}
