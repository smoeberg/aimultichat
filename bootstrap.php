<?php
declare(strict_types=1);

function loadEnv(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            continue;
        }

        if (($value[0] ?? '') === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
            $value = preg_replace('/\\\\(["\\\\])/', '$1', $value) ?? $value;
        } elseif (($value[0] ?? '') === "'" && substr($value, -1) === "'") {
            $value = substr($value, 1, -1);
        }

        if (getenv($key) === false) {
            $_ENV[$key] = $value;
        }
    }
}

function configValue(string $key, ?string $default = null): ?string
{
    $runtimeValue = getenv($key);
    if ($runtimeValue !== false && $runtimeValue !== '') {
        return (string)$runtimeValue;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }

    return $default;
}

function configBool(string $key, bool $default = false): bool
{
    $value = configValue($key);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

loadEnv(__DIR__ . '/config/.env');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = __DIR__ . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php-error.log');

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Core\\' => __DIR__ . '/src/Core/',
        'Models\\' => __DIR__ . '/src/Models/',
        'Services\\' => __DIR__ . '/src/Services/',
        'Controllers\\' => __DIR__ . '/src/Controllers/',
    ];

    foreach ($prefixes as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});

set_exception_handler(static function (Throwable $exception): void {
    $requestId = bin2hex(random_bytes(8));
    if (class_exists('Core\\Logger')) {
        Core\Logger::error('Unhandled exception', [
            'request_id' => $requestId,
            'type' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
    } else {
        @error_log("Unhandled exception [{$requestId}]");
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Intern serverfejl. Reference: {$requestId}";
    exit;
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');

    if (configValue('APP_ENV', 'production') === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    sendSecurityHeaders();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    $isHttps = (($_SERVER['HTTPS'] ?? 'off') !== 'off');
    if (configBool('TRUST_PROXY_HEADERS', false)) {
        $isHttps = $isHttps || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
    if (configBool('SESSION_SECURE', configValue('APP_ENV', 'production') === 'production')) {
        $isHttps = true;
    }

    session_name('multichat_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
