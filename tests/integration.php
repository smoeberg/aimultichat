<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Core\Database;
use Core\Migrator;
use Core\Security;
use Models\Bot;
use Models\Chat;
use Services\RateLimiter;
use Services\SettingsService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$database = Database::getInstance();
$schema = file_get_contents(__DIR__ . '/../database.sql');
if (!is_string($schema)) {
    throw new RuntimeException('Databaseskemaet kunne ikke læses.');
}
foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [])) as $statement) {
    $database->exec($statement);
}

$firstMigrationRun = Migrator::run($database, __DIR__ . '/../migrations');
$assert(in_array('001_security_hardening.sql', $firstMigrationRun, true), 'Migrationen skal anvendes første gang.');
$assert(Migrator::run($database, __DIR__ . '/../migrations') === [], 'Migrationer skal være idempotente.');

$database->exec('DELETE FROM rate_limit_buckets');
$database->exec('DELETE FROM messages');
$database->exec('DELETE FROM conversations');
$database->exec('DELETE FROM bots');
$database->exec('DELETE FROM app_settings');
$database->exec('DELETE FROM users');

$createUser = $database->prepare(
    'INSERT INTO users(name, username, password_hash, role, enabled) VALUES(?, ?, ?, ?, 1)'
);
$createUser->execute(['Integration', 'integration', Security::hashPassword('integration-password'), 'user']);
$userId = (int)$database->lastInsertId();

$chat = Chat::create($userId, 'Krypteret titel');
$rawTitle = (string)$database->query(
    'SELECT title FROM conversations WHERE id = ' . $chat->id
)->fetchColumn();
$assert(str_starts_with($rawTitle, 'enc:v1:'), 'Samtaletitlen skal lagres krypteret.');
$assert($chat->getTitle() === 'Krypteret titel', 'Samtaletitlen skal kunne dekrypteres.');

$chat->addExchange('Brugerbesked', 'Assistentsvar', null, 'Ny titel');
$rawMessages = $database->query(
    'SELECT role, content FROM messages WHERE conversation_id = ' . $chat->id . ' ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$assert(count($rawMessages) === 2, 'Et beskedskifte skal gemmes som præcis to rækker.');
$assert(
    str_starts_with((string)($rawMessages[0]['content'] ?? ''), 'enc:v1:'),
    'Brugerbeskeden skal lagres krypteret.'
);
$messages = $chat->getMessages(10);
$assert(($messages[0]['content'] ?? '') === 'Brugerbesked', 'Brugerbeskeden skal kunne dekrypteres.');
$assert(($messages[1]['content'] ?? '') === 'Assistentsvar', 'Assistentsvaret skal kunne dekrypteres.');

SettingsService::put('integration_secret', 'hemmelig', true);
$storedSecret = (string)$database->query(
    "SELECT value FROM app_settings WHERE setting_key = 'integration_secret'"
)->fetchColumn();
$assert(str_starts_with($storedSecret, 'enc:v1:'), 'Indstillings-secrets skal lagres krypteret.');
$assert(SettingsService::getSecret('integration_secret') === 'hemmelig', 'Indstillings-secrets skal kunne læses.');

$bot = Bot::createOrUpdate([
    'bot_key' => 'integration-bot',
    'name' => 'Integration Bot',
    'provider' => 'openai',
    'endpoint' => 'https://api.openai.com/v1/chat/completions',
    'model' => 'integration-model',
    'api_key' => 'integration-api-key',
    'system_prompt' => 'Test',
    'enabled' => 1,
]);
$publicBot = $bot->toPublicArray();
$assert($bot->isConfigured(), 'Botten skal registreres som konfigureret.');
$assert(!array_key_exists('endpoint', $publicBot), 'Det offentlige botobjekt må ikke afsløre endpoint.');
$assert(!array_key_exists('system_prompt', $publicBot), 'Det offentlige botobjekt må ikke afsløre system-prompt.');

$limiter = new RateLimiter('integration', 2, 60);
$assert($limiter->check($userId), 'Første rate-limit-kald skal accepteres.');
$assert($limiter->check($userId), 'Andet rate-limit-kald skal accepteres.');
$assert(!$limiter->check($userId), 'Kald over rate-limit-grænsen skal afvises.');

$chat->delete();
$remainingMessages = (int)$database->query(
    'SELECT COUNT(*) FROM messages WHERE conversation_id = ' . $chat->id
)->fetchColumn();
$assert($remainingMessages === 0, 'Chat-sletning skal cascade-slette beskeder.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Alle databaseintegrationstests bestod.\n";
