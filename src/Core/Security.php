<?php
declare(strict_types=1);

namespace Core;

use RuntimeException;
use InvalidArgumentException;

final class Security
{
    public const MIN_PASSWORD_LENGTH = 12;
    private const ENCRYPTED_PREFIX = 'enc:v1:';

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

        return self::ENCRYPTED_PREFIX . base64_encode($iv . $tag . $ct);
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

        if (str_starts_with($data, self::ENCRYPTED_PREFIX)) {
            $data = substr($data, strlen(self::ENCRYPTED_PREFIX));
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
        if (mb_strlen($p) < self::MIN_PASSWORD_LENGTH) {
            throw new InvalidArgumentException('Adgangskoden skal være mindst 12 tegn.');
        }
        $hash = password_hash($p, self::passwordAlgorithm());
        if (!is_string($hash)) {
            throw new RuntimeException('Adgangskoden kunne ikke hashes sikkert.');
        }
        return $hash;
    }

    public static function verifyPassword(string $p, string $h): bool
    {
        return $h !== '' && password_verify($p, $h);
    }

    public static function passwordNeedsRehash(string $hash): bool
    {
        return $hash !== '' && password_needs_rehash($hash, self::passwordAlgorithm());
    }

    private static function passwordAlgorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? (string)constant('PASSWORD_ARGON2ID') : PASSWORD_DEFAULT;
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
            throw new RuntimeException('Ugyldig forespørgselsmetode', 405);
        }

        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $headers = array_change_key_case($headers, CASE_LOWER);
        $token = $headers['x-csrf-token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_POST['csrf']
            ?? '';

        if (!self::validateCsrf((string)$token)) {
            throw new RuntimeException('Ugyldigt CSRF token', 403);
        }
    }

    public static function encryptIfConfigured(string $data): string
    {
        if (self::getKeyBytes('ENCRYPTION_KEY') === null) {
            if (\configValue('APP_ENV', 'production') === 'production') {
                throw new RuntimeException('ENCRYPTION_KEY mangler i produktionskonfigurationen.');
            }
            return $data;
        }
        return self::encrypt($data);
    }

    public static function decryptOrPlaintext(mixed $data): string
    {
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        $value = (string)($data ?? '');
        if ($value === '') {
            return '';
        }

        $result = self::decryptWithStatus($value);
        if ($result['data'] !== null) {
            return $result['data'];
        }
        if (str_starts_with(trim($value), self::ENCRYPTED_PREFIX)) {
            throw new RuntimeException('Krypterede data kunne ikke dekrypteres med den konfigurerede nøgle.');
        }
        return $value;
    }

    public static function isVersionedEncryptedValue(mixed $data): bool
    {
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        return str_starts_with(trim((string)($data ?? '')), self::ENCRYPTED_PREFIX);
    }

    public static function looksLikeLegacyEncryptedValue(mixed $data): bool
    {
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        $raw = base64_decode(trim((string)($data ?? '')), true);
        return is_string($raw) && strlen($raw) >= 28;
    }
}
