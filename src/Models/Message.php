<?php
declare(strict_types=1);
namespace Models;
use Core\Database;
final class Message {
    public static function forChat(int $chatId,int $limit=100): array {
        $limit=max(1,min(200,$limit)); $db=Database::getInstance();
        $stmt=$db->prepare("SELECT id,role,content,bot_id,created_at FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT {$limit}");
        $stmt->execute([$chatId]); return array_reverse($stmt->fetchAll());
    }
}
