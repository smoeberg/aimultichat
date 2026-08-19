<?php
declare(strict_types=1);

namespace Controllers;

use Core\Logger;
use Core\Database;
use Core\Security;
use Models\User;
use Services\RateLimiter;

final class AuthController
{
    public function login(): void
    {
        \startSecureSession();
        try {
            Security::requirePostCsrf();
        } catch (\RuntimeException) {
            $this->rejectLogin();
        }

        $username = trim((string)($_POST['login_username'] ?? ''));
        $password = (string)($_POST['login_password'] ?? '');
        $normalizedUsername = strtolower($username);
        $clientIp = $this->clientIp();
        $accountLimiter = new RateLimiter(
            'login-account',
            max(1, (int)(\configValue('LOGIN_RATE_LIMIT_MAX', '5') ?? 5)),
            max(1, (int)(\configValue('LOGIN_RATE_LIMIT_WINDOW', '300') ?? 300))
        );
        $ipLimiter = new RateLimiter(
            'login-ip',
            max(1, (int)(\configValue('LOGIN_IP_RATE_LIMIT_MAX', '20') ?? 20)),
            max(1, (int)(\configValue('LOGIN_RATE_LIMIT_WINDOW', '300') ?? 300))
        );

        $accountAllowed = $accountLimiter->check($normalizedUsername ?: '<empty>');
        $ipAllowed = $ipLimiter->check($clientIp);
        if (!$accountAllowed || !$ipAllowed) {
            Logger::warning('Login rate limit exceeded', [
                'account_hash' => hash('sha256', $normalizedUsername),
                'ip_hash' => hash('sha256', $clientIp),
            ]);
            $this->rejectLogin();
        }

        $user = $username !== '' ? User::findByUsername($username) : null;
        if ($user === null || !Security::verifyPassword($password, $user->passwordHash ?? '')) {
            usleep(200000);
            $this->rejectLogin();
        }
        if (Security::passwordNeedsRehash($user->passwordHash ?? '')) {
            $hash = Security::hashPassword($password);
            $statement = Database::getInstance()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $statement->execute([$hash, $user->id]);
        }

        session_regenerate_id(true);
        $_SESSION['uid'] = $user->id;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['authenticated_at'] = time();
        header('Location: index.php');
        exit;
    }

    public function logout(): void
    {
        \startSecureSession();
        try {
            Security::requirePostCsrf();
        } catch (\RuntimeException) {
            http_response_code(403);
            exit('Ugyldig forespørgsel.');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'] ?? '',
                'secure' => (bool)$parameters['secure'],
                'httponly' => (bool)$parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Strict',
            ]);
        }
        session_destroy();
        header('Location: index.php');
        exit;
    }

    private function rejectLogin(): never
    {
        header('Location: index.php?error=login');
        exit;
    }

    private function clientIp(): string
    {
        $candidate = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (\configBool('TRUST_PROXY_HEADERS', false)) {
            $forwarded = explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0] ?? '';
            if (filter_var(trim($forwarded), FILTER_VALIDATE_IP)) {
                $candidate = trim($forwarded);
            }
        }
        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : 'unknown';
    }
}
