<?php
declare(strict_types=1);
namespace Services;

use Core\Database;
use Core\Security;

final class SettingsService {
    private static bool $schemaReady = false;

    /** Backward-compatible schema migration for installations created before settings existed. */
    private static function ensureSchema(): void {
        if (self::$schemaReady) return;
        Database::getInstance()->exec(
            'CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(100) NOT NULL PRIMARY KEY, value MEDIUMTEXT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$schemaReady = true;
    }
    public static function get(string $key, ?string $default = null): ?string {
        self::ensureSchema();
        $stmt = Database::getInstance()->prepare('SELECT value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row || !array_key_exists('value', $row)) return $default;
        return (string)$row['value'];
    }

    public static function getSecret(string $key): ?string {
        $value = self::get($key);
        if ($value === null || $value === '') return null;
        try { return Security::decrypt($value); } catch (\Throwable) { return null; }
    }

    public static function put(string $key, string $value, bool $secret = false): void {
        self::ensureSchema();
        $stored = $secret ? Security::encrypt($value) : $value;
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO app_settings(setting_key, value) VALUES(?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute([$key, $stored]);
    }
}
