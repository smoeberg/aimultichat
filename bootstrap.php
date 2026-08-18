<?php
declare(strict_types=1);
function loadEnv(string $file): void {
    if(!is_file($file)) return;
    foreach(file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
        $line=trim($line); if($line===''||str_starts_with($line,'#')||!str_contains($line,'=')) continue;
        [$k,$v]=explode('=',$line,2); $k=trim($k); $v=trim($v);
        if(($v[0]??'')==='"' && substr($v,-1)==='"') {
            $v=substr($v,1,-1);
            $v=preg_replace('/\\\\(["\\\\])/', '$1', $v) ?? $v;
        } elseif(($v[0]??'')==="'" && substr($v,-1)==="'") {
            $v=substr($v,1,-1);
        }
        $_ENV[$k]=$v;
    }
}
function configValue(string $key, ?string $default=null): ?string {
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    $value=getenv($key);
    if ($value !== false) return (string)$value;
    return $_ENV[$key] ?? $default;
}
loadEnv(__DIR__.'/config/.env');

// Never expose PHP errors to visitors; write them to the application log instead.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = __DIR__ . '/storage/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0750, true); }
ini_set('error_log', $logDir . '/php-error.log');
set_exception_handler(function (Throwable $e): void {
    if (class_exists('Core\Logger')) {
        Core\Logger::error('Unhandled exception', ['type' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    } else {
        @error_log($e->getMessage());
    }
    if (!headers_sent()) { http_response_code(500); }
    echo 'Intern serverfejl. Se storage/logs/php-error.log.';
    exit;
});
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
spl_autoload_register(function(string $class){
    $prefixes=['Core\\'=>__DIR__.'/src/Core/','Models\\'=>__DIR__.'/src/Models/','Services\\'=>__DIR__.'/src/Services/','Controllers\\'=>__DIR__.'/src/Controllers/'];
    foreach($prefixes as $prefix=>$base){ if(str_starts_with($class,$prefix)){ $file=$base.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; if(is_file($file)) require_once $file; return; } }
});
function sendSecurityHeaders(): void { if (headers_sent()) return; header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: strict-origin-when-cross-origin'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()'); if (configValue('APP_ENV','production') === 'production') header('Strict-Transport-Security: max-age=31536000; includeSubDomains'); }
function startSecureSession(): void {
    if(session_status()===PHP_SESSION_ACTIVE) return;
    sendSecurityHeaders();
    $secure=(($_SERVER['HTTPS'] ?? 'off') !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('multichat_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if(empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
}
