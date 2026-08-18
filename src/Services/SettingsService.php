<?php
declare(strict_types=1);

namespace Services;

use Core\Database;
use Core\Security;

final class SettingsService
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT value FROM app_settings WHERE setting_key = ? LIMIT 1'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if (!$row || !array_key_exists('value', $row)) {
            return $default;
        }
        return (string)$row['value'];
    }

    public static function getList(string $key): array
    {
        $value = self::get($key, '') ?? '';
        $items = preg_split('/[\r\n,]+/', $value) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $items))));
    }

    public static function getSecret(string $key): ?string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Security::decrypt($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function put(string $key, string $value, bool $secret = false): void
    {
        if (!preg_match('/^[a-z0-9_.-]{1,100}$/i', $key)) {
            throw new \InvalidArgumentException('Ugyldig indstillingsnøgle.');
        }
        $stored = $secret && $value !== '' ? Security::encrypt($value) : $value;
        $stmt = Database::getInstance()->prepare(
            'INSERT INTO app_settings(setting_key, value) VALUES(?, ?) '
            . 'ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute([$key, $stored]);
    }
}
