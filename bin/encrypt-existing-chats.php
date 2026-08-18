<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Core\Database;
use Core\Security;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$database = Database::getInstance();
$updated = 0;

/**
 * @return int number of updated rows
 */
$migrateColumn = static function (string $table, string $column) use ($database): int {
    $allowed = [
        'conversations.title' => true,
        'messages.content' => true,
    ];
    if (!isset($allowed["{$table}.{$column}"])) {
        throw new RuntimeException('Ugyldigt migreringsmål.');
    }

    $lastId = 0;
    $count = 0;
    do {
        $select = $database->prepare(
            "SELECT id, {$column} AS value FROM {$table} WHERE id > ? ORDER BY id ASC LIMIT 200"
        );
        $select->execute([$lastId]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }

        $database->beginTransaction();
        try {
            $update = $database->prepare("UPDATE {$table} SET {$column} = ? WHERE id = ?");
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $value = (string)($row['value'] ?? '');
                if ($value === '') {
                    continue;
                }

                $status = Security::decryptWithStatus($value);
                if (Security::isVersionedEncryptedValue($value) && $status['status'] === 'VALID') {
                    continue;
                }
                if (Security::isVersionedEncryptedValue($value) && $status['data'] === null) {
                    throw new RuntimeException(
                        "Versioneret ciphertext i {$table}.{$column} id {$lastId} kan ikke dekrypteres. Kontrollér ENCRYPTION_KEY."
                    );
                }
                if ($status['data'] !== null) {
                    $plaintext = $status['data'];
                } elseif (Security::looksLikeLegacyEncryptedValue($value)) {
                    throw new RuntimeException(
                        "Mulig ciphertext i {$table}.{$column} id {$lastId} kan ikke dekrypteres. Kontrollér ENCRYPTION_KEY."
                    );
                } else {
                    $plaintext = $value;
                }

                $update->execute([Security::encrypt($plaintext), $lastId]);
                $count++;
            }
            $database->commit();
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    } while (count($rows) === 200);

    return $count;
};

$updated += $migrateColumn('conversations', 'title');
$updated += $migrateColumn('messages', 'content');

echo "Krypterede eller opgraderede rækker: {$updated}\n";
