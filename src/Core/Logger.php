<?php
declare(strict_types=1);

namespace Core;

final class Logger
{
    private const SENSITIVE_KEY_PATTERN = '/password|passwd|secret|token|api.?key|authorization|cookie|credential/i';

    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $path = \configValue('LOG_PATH', __DIR__ . '/../../storage/logs/app.log')
            ?? __DIR__ . '/../../storage/logs/app.log';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $entry = [
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => mb_substr($message, 0, 500),
            'context' => self::sanitizeContext($context),
        ];
        $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($encoded)) {
            @file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private static function sanitizeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string)$key)) {
                $safe[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::sanitizeContext($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 1000) : $value;
            } else {
                $safe[$key] = '[unsupported]';
            }
        }
        return $safe;
    }
}
