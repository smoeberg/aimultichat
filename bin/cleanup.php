<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$retentionDays = max(0, (int)(configValue('CHAT_RETENTION_DAYS', '0') ?? 0));
if ($retentionDays === 0) {
    echo "Automatisk chatretention er deaktiveret.\n";
    exit;
}

$cutoff = gmdate('Y-m-d H:i:s', time() - ($retentionDays * 86400));
$statement = Database::getInstance()->prepare('DELETE FROM conversations WHERE updated_at < ?');
$statement->execute([$cutoff]);
echo 'Slettede samtaler: ' . $statement->rowCount() . "\n";
