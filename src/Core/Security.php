<?php
declare(strict_types=1);

namespace Core;

use RuntimeException;
use InvalidArgumentException;

final class Security 
{
    public static function generateSessionToken(): string 
    { 
        return bin2hex(random_bytes(32)); 
    }

    /**
     * Krypterer data med den primære ENCRYPTION_KEY (AES-256-GCM).
     */
    public static function encrypt(string $data): string 
    { 
        $key = self::getKeyBytes('ENCRYPTION_KEY');
        if ($key === null) {
            throw new RuntimeException('Primær krypteringsnøgle (ENCRYPTION_KEY) mangler i konfigurationen.');
        }

        $iv = random_bytes(12);
        $tag = '';

        $ct = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('Kryptering mislykkedes.');
        }

        return base64_encode($iv . $tag . $ct); 
    }

    /**
     * Dekrypterer data. Kaster exception ved fejl.
     */
    public static function decrypt(string $data): string 
    { 
        $result = self::decryptWithStatus($data);
        if ($result['status'] === 'UNREADABLE' || $result['data'] === null) {
            throw new RuntimeException('Dekryptering mislykkedes.');
        }

        return $result['data'];
    }

    /**
     * Dekrypterer data og returnerer både afkodet tekst samt nøglestatus.
     * Håndterer også PDO BLOB-streams automatisk.
     *
     * @param string|resource|null $data
     * @return array{data: string|null, status: 'VALID'|'MIGRATED'|'UNREADABLE'|'MISSING'}
     */
    public static function decryptWithStatus(mixed $data): array
    {
        if (is_resource($data)) {
            $data = (string)stream_get_contents($data);
        } else {
            $data = (string)($data ?? '');
        }

        $data = trim($data);
        if ($data === '') {
            return ['data' => '', 'status' => 'MISSING'];
        }

        $raw = base64_decode($data, true);
        if ($raw === false || strlen($raw) < 28) {
            return ['data' => null, 'status' => 'UNREADABLE'];
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);

        // 1. Forsøg med primær nøgle (ENCRYPTION_KEY)
        $primaryKey = self::getKeyBytes('ENCRYPTION_KEY');
        if ($primaryKey !== null) {
            $decrypted = openssl_decrypt($ct, 'aes-256-gcm', $primaryKey, OPENSSL_RAW_DATA, $iv, $tag, '');
            if ($decrypted !== false) {
                return ['data' => $decrypted, 'status' => 'VALID'];
            }
        }

        // 2. Forsøg med gammel nøgle (OLD_ENCRYPTION_KEY / ENCRYPTION_KEY_OLD)
        $oldKey = self::getKeyBytes('OLD_ENCRYPTION_KEY') ?? self::getKeyBytes('ENCRYPTION_KEY_OLD');
        if ($oldKey !== null) {
            $decrypted = openssl_decrypt($ct, 'aes-256-gcm', $oldKey, OPENSSL_RAW_DATA, $iv, $tag, '');
            if ($decrypted !== false) {
                return ['data' => $decrypted, 'status' => 'MIGRATED'];
            }
        }

        return ['data' => null, 'status' => 'UNREADABLE'];
    }

    /**
     * Konverterer konfigurationsnøglen til 32-bytes (256-bit binary) uanset format.
     */
    private static function getKeyBytes(string $configName): ?string
    {
        $val = \configValue($configName, '') ?? '';
        $val = is_string($val) ? trim($val) : '';

        if ($val === '') {
            return null;
        }

        // Hvis det allerede er en 64-tegns hex-streng (256-bit hex)
        if (preg_match('/^[a-f0-9]{64}$/i', $val)) {
            return hex2bin($val);
        }

        // For alle andre streng-formater danner vi en konsistent 256-bit nøgle via SHA-256
        return hash('sha256', $val, true);
    }

    public static function hashPassword(string $p): string 
    { 
        if (strlen($p) < 6) {
            throw new InvalidArgumentException('Adgangskoden skal være mindst 6 tegn.');
        }
        return password_hash($p, PASSWORD_ARGON2ID); 
    }

    public static function verifyPassword(string $p, string $h): bool 
    {
        return $h !== '' && password_verify($p, $h);
    }

    public static function sanitizeInput(string $s): string 
    { 
        return trim($s); 
    }

    public static function validateCsrf(string $token): bool 
    {
        return isset($_SESSION['csrf_token']) && $token !== '' && hash_equals((string)$_SESSION['csrf_token'], $token);
    }

    public static function requirePostCsrf(): void 
    { 
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !self::validateCsrf((string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Ugyldig forespørgsel (CSRF)', 403);
        } 
    }

    public static function requireCsrfHeader(): void 
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
        $token = $headers['x-csrf-token'] ?? $_POST['csrf'] ?? '';

        if (!self::validateCsrf((string)$token)) {
            throw new RuntimeException('Ugyldigt CSRF token', 403);
        }
    }
}