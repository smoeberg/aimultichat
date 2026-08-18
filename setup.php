<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
startSecureSession();

use Core\Logger;
use Core\Migrator;
use Core\Security;

$envFile = __DIR__ . '/config/.env';
$lockFile = __DIR__ . '/storage/install.lock';
$error = '';
$done = false;

foreach (['pdo_mysql', 'openssl', 'curl', 'mbstring', 'json'] as $extension) {
    if (!extension_loaded($extension)) {
        $error = "PHP-udvidelsen {$extension} mangler på serveren.";
        break;
    }
}
if (is_file($lockFile)) {
    http_response_code(403);
    exit('Installationen er allerede gennemført. Brug bin/migrate.php til databaseopdateringer.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    try {
        if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Ugyldig forespørgsel.');
        }

        $host = trim((string)($_POST['db_host'] ?? ''));
        $port = (int)($_POST['db_port'] ?? 3306);
        $databaseName = trim((string)($_POST['db_name'] ?? ''));
        $databaseUser = trim((string)($_POST['db_user'] ?? ''));
        $databasePassword = (string)($_POST['db_password'] ?? '');
        $admin = trim((string)($_POST['admin_username'] ?? ''));
        $adminPassword = (string)($_POST['admin_password'] ?? '');

        if ($port < 1 || $port > 65535 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,253}$/', $host)) {
            throw new RuntimeException('Ugyldig databasevært eller port.');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $databaseName) || $databaseUser === '') {
            throw new RuntimeException('Ugyldigt databasenavn eller databasebruger.');
        }
        if (!filter_var($admin, FILTER_VALIDATE_EMAIL)
            && !preg_match('/^[A-Za-z0-9_.@-]{3,100}$/', $admin)) {
            throw new RuntimeException('Ugyldigt administratorbrugernavn.');
        }
        if (mb_strlen($adminPassword) < 12) {
            throw new RuntimeException('Administratoradgangskoden skal være mindst 12 tegn.');
        }

        $configDir = dirname($envFile);
        if (!is_dir($configDir) && !mkdir($configDir, 0750, true)) {
            throw new RuntimeException('Mappen config kunne ikke oprettes.');
        }
        if (!is_writable($configDir)) {
            throw new RuntimeException('PHP-processen mangler skrivetilladelse til config.');
        }

        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
            $databaseUser,
            $databasePassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $schema = file_get_contents(__DIR__ . '/database.sql');
        if (!is_string($schema)) {
            throw new RuntimeException('Databaseskemaet kunne ikke læses.');
        }
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [])) as $sql) {
            $pdo->exec($sql);
        }
        Migrator::run($pdo, __DIR__ . '/migrations');

        $existingKey = configValue('ENCRYPTION_KEY', '') ?? '';
        $key = preg_match('/^[0-9a-fA-F]{64}$/', $existingKey)
            ? $existingKey
            : bin2hex(random_bytes(32));
        $envValue = static fn(string $value): string => '"'
            . str_replace(["\\", '"', "\r", "\n"], ["\\\\", '\\"', '', ''], $value)
            . '"';
        $env = "DB_HOST=" . $envValue($host) . "\n"
            . "DB_PORT={$port}\n"
            . "DB_NAME=" . $envValue($databaseName) . "\n"
            . "DB_USER=" . $envValue($databaseUser) . "\n"
            . "DB_PASSWORD=" . $envValue($databasePassword) . "\n"
            . "ENCRYPTION_KEY={$key}\n"
            . "APP_ENV=production\nSESSION_SECURE=true\nSESSION_MAX_AGE=28800\nTRUST_PROXY_HEADERS=false\n"
            . "RATE_LIMIT_MAX_REQUESTS=20\nRATE_LIMIT_WINDOW=60\n"
            . "LOGIN_RATE_LIMIT_MAX=5\nLOGIN_IP_RATE_LIMIT_MAX=20\nLOGIN_RATE_LIMIT_WINDOW=300\n"
            . "MAX_MESSAGE_CHARS=20000\nMAX_RESPONSE_CHARS=50000\n"
            . "MAX_HISTORY_MESSAGES=40\nMAX_HISTORY_CHARS=100000\nCHAT_RETENTION_DAYS=0\n"
            . "PROVIDER_ALLOWED_HOSTS=\nPROVIDER_ALLOWED_PORTS=443\nPROVIDER_ALLOW_PRIVATE_NETWORKS=false\n"
            . "PROVIDER_CONNECT_TIMEOUT=10\nPROVIDER_TIMEOUT=45\nPROVIDER_MAX_RESPONSE_BYTES=1048576\n"
            . "GITHUB_CONTEXT_MAX_FILES=10\nGITHUB_CONTEXT_FILE_BYTES=3000\n"
            . "GITHUB_CONTEXT_MAX_BYTES=40000\nGITHUB_API_MAX_RESPONSE_BYTES=10485760\n"
            . "LOG_PATH=" . $envValue(__DIR__ . '/storage/logs/app.log') . "\n";

        if (file_put_contents($envFile, $env, LOCK_EX) === false || !chmod($envFile, 0600)) {
            throw new RuntimeException('Konfigurationsfilen kunne ikke skrives sikkert.');
        }
        foreach (['DB_HOST' => $host, 'DB_PORT' => (string)$port, 'DB_NAME' => $databaseName,
            'DB_USER' => $databaseUser, 'DB_PASSWORD' => $databasePassword, 'ENCRYPTION_KEY' => $key] as $name => $value) {
            $_ENV[$name] = $value;
        }

        if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            $createAdmin = $pdo->prepare(
                'INSERT INTO users(name, username, password_hash, role, enabled) '
                . 'VALUES(?, ?, ?, "admin", 1)'
            );
            $createAdmin->execute(['Administrator', $admin, Security::hashPassword($adminPassword)]);
        }
        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0750, true);
        }
        if (file_put_contents($lockFile, gmdate('c'), LOCK_EX) === false || !chmod($lockFile, 0600)) {
            throw new RuntimeException('Installationslåsen kunne ikke skrives sikkert.');
        }
        $done = true;
    } catch (PDOException $exception) {
        Logger::error('Installation database error', [
            'driver_code' => (int)($exception->errorInfo[1] ?? 0),
        ]);
        $error = 'Databasen afviser forbindelsen eller skemaopdateringen. Kontrollér oplysninger og rettigheder.';
    } catch (Throwable $exception) {
        Logger::error('Installation failed', ['type' => get_class($exception)]);
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Installationen mislykkedes. Se serverens applikationslog.';
    }
}
?>
<!doctype html>
<html lang="da">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Multi-Chat – Installation</title>
    <style>body{font-family:system-ui;max-width:760px;margin:40px auto;padding:20px}label{display:block;margin:12px 0}input{width:100%;padding:10px;box-sizing:border-box}.error{background:#fee;padding:12px}.ok{background:#efe;padding:12px}</style>
</head>
<body>
<h1>Installation af Multi-Chat</h1>
<?php if ($done): ?>
    <div class="ok">Installationen er gennemført. Luk den beskyttede setup-location og åbn applikationen.</div>
<?php else: ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <h2>Database</h2>
        <label>Vært<input name="db_host" placeholder="db.example.com" required></label>
        <label>Port<input type="number" name="db_port" value="3306" min="1" max="65535" required></label>
        <label>Database<input name="db_name" value="multichat" required></label>
        <label>Bruger<input name="db_user" required></label>
        <label>Adgangskode<input type="password" name="db_password" required></label>
        <h2>Administrator</h2>
        <label>Brugernavn<input name="admin_username" required></label>
        <label>Adgangskode (mindst 12 tegn)<input type="password" name="admin_password" required minlength="12"></label>
        <button type="submit">Installér</button>
    </form>
<?php endif; ?>
</body>
</html>
