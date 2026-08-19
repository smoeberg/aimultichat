<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Core\Database;
use Core\Migrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$applied = Migrator::run(Database::getInstance(), __DIR__ . '/../migrations');
echo $applied === []
    ? "Databasen er allerede opdateret.\n"
    : 'Anvendte migrationer: ' . implode(', ', $applied) . "\n";
