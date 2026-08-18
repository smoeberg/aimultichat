<?php
declare(strict_types=1);

putenv('APP_ENV=testing');
putenv('ENCRYPTION_KEY=' . str_repeat('ab', 32));
putenv('PROVIDER_ALLOW_PRIVATE_NETWORKS=true');

require_once __DIR__ . '/../bootstrap.php';

use Core\Security;
use Services\BotService;
use Services\GitHubService;
use Services\HttpJsonClient;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$ciphertext = Security::encrypt('hemmelig tekst');
$assert($ciphertext !== 'hemmelig tekst', 'Security::encrypt skal kryptere data.');
$assert(str_starts_with($ciphertext, 'enc:v1:'), 'Ny ciphertext skal have en versionsmarkør.');
$assert(Security::decrypt($ciphertext) === 'hemmelig tekst', 'Kryptering skal kunne round-trippes.');
$assert(
    Security::decrypt(substr($ciphertext, strlen('enc:v1:'))) === 'hemmelig tekst',
    'Ældre ciphertext uden versionsmarkør skal stadig kunne læses.'
);
$assert(Security::decryptOrPlaintext('ældre klartekst') === 'ældre klartekst', 'Eksisterende klartekst skal kunne læses.');
try {
    Security::decryptOrPlaintext('enc:v1:' . base64_encode(str_repeat('x', 40)));
    $failures[] = 'Beskadiget versioneret ciphertext blev behandlet som klartekst.';
} catch (RuntimeException) {
}

$github = new GitHubService();
$assert(
    $github->repositoryFullName('https://github.com/smoeberg/aimultichat/tree/main') === 'smoeberg/aimultichat',
    'GitHub URL skal normaliseres til ejer/repository.'
);
$assert(
    $github->isAllowedRepository('SMOEBERG/AIMULTICHAT', ['smoeberg/aimultichat']),
    'Repository-allowlist skal være case-insensitive.'
);
$redacted = $github->redactSecrets("API_KEY=abc123\nnormal=value\n");
$assert(!str_contains($redacted, 'abc123'), 'Repository-secrets skal redigeres.');
$assert(str_contains($redacted, 'normal=value'), 'Almindelig repository-kontekst skal bevares.');
$literalToken = 'ghp_' . str_repeat('a', 36);
$assert(
    !str_contains($github->redactSecrets("const value = '{$literalToken}';"), $literalToken),
    'Kendte token-litteraler skal redigeres.'
);

$botService = new BotService();
$assert(
    $botService->extractText([['type' => 'text', 'text' => 'A'], ['type' => 'text', 'text' => 'B']]) === "A\nB",
    'Content blocks skal normaliseres til tekst.'
);
$messages = $botService->formatMessagesForApi(
    [['role' => 'model', 'content' => [['text' => 'Svar']]]],
    'System'
);
$assert($messages[0] === ['role' => 'system', 'content' => 'System'], 'System-prompt skal stå først.');
$assert($messages[1] === ['role' => 'assistant', 'content' => 'Svar'], 'Model-rollen skal normaliseres.');

$assert(
    HttpJsonClient::validateEndpoint('https://api.openai.com/v1/chat/completions', 'openai')
        === 'https://api.openai.com/v1/chat/completions',
    'Kendt HTTPS-provider skal accepteres.'
);
try {
    HttpJsonClient::validateEndpoint('http://127.0.0.1/admin', 'openai');
    $failures[] = 'HTTP/private endpoint blev ikke afvist.';
} catch (InvalidArgumentException) {
}
try {
    HttpJsonClient::validateEndpoint('https://api.openai.com:8443/v1/chat/completions', 'openai');
    $failures[] = 'Ikke-godkendt provider-port blev ikke afvist.';
} catch (InvalidArgumentException) {
}

foreach ([
    __DIR__ . '/../config/env.txt',
    __DIR__ . '/../config/.env',
    __DIR__ . '/../storage/install.lock',
    __DIR__ . '/../storage/logs/app.log',
] as $forbiddenPath) {
    $assert(!is_file($forbiddenPath), 'Runtimefil må ikke være committed: ' . $forbiddenPath);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "Alle tests bestod.\n";
