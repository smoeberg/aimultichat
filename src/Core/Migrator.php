<?php
declare(strict_types=1);

namespace Core;

final class Migrator
{
    public static function run(\PDO $database, string $directory): array
    {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS database_migrations ('
            . 'migration VARCHAR(255) PRIMARY KEY, '
            . 'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $applied = [];
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $exists = $database->prepare('SELECT 1 FROM database_migrations WHERE migration = ?');
        $record = $database->prepare('INSERT INTO database_migrations(migration) VALUES(?)');

        foreach ($files as $file) {
            $migration = basename($file);
            $exists->execute([$migration]);
            if ($exists->fetchColumn()) {
                continue;
            }
            $sql = file_get_contents($file);
            if (!is_string($sql)) {
                throw new \RuntimeException("Migrationen {$migration} kunne ikke læses.");
            }
            foreach (self::statements($sql) as $statement) {
                $database->exec($statement);
            }
            $record->execute([$migration]);
            $applied[] = $migration;
        }
        return $applied;
    }

    private static function statements(string $sql): array
    {
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/;\s*(?:\r?\n|$)/', $withoutComments) ?: []
        )));
    }
}
