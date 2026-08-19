<?php
declare(strict_types=1);

namespace Services;

use Core\Database;

final class RateLimiter
{
    private \PDO $database;

    public function __construct(
        private readonly string $scope = 'chat',
        private readonly int $maxRequests = 20,
        private readonly int $windowSeconds = 60
    ) {
        if (!preg_match('/^[a-z0-9_.-]{1,32}$/i', $scope)) {
            throw new \InvalidArgumentException('Ugyldigt rate-limit scope.');
        }
        $this->database = Database::getInstance();
    }

    public function check(string|int $subject): bool
    {
        $max = max(1, $this->maxRequests);
        $window = max(1, $this->windowSeconds);
        $subjectHash = hash('sha256', (string)$subject);
        $windowStart = intdiv(time(), $window) * $window;
        $windowDate = gmdate('Y-m-d H:i:s', $windowStart);

        $this->database->beginTransaction();
        try {
            $insert = $this->database->prepare(
                'INSERT IGNORE INTO rate_limit_buckets '
                . '(scope, subject_hash, window_start, request_count) VALUES (?, ?, ?, 0)'
            );
            $insert->execute([$this->scope, $subjectHash, $windowDate]);

            $select = $this->database->prepare(
                'SELECT request_count FROM rate_limit_buckets '
                . 'WHERE scope = ? AND subject_hash = ? AND window_start = ? FOR UPDATE'
            );
            $select->execute([$this->scope, $subjectHash, $windowDate]);
            $count = (int)$select->fetchColumn();
            if ($count >= $max) {
                $this->database->commit();
                return false;
            }

            $update = $this->database->prepare(
                'UPDATE rate_limit_buckets SET request_count = request_count + 1 '
                . 'WHERE scope = ? AND subject_hash = ? AND window_start = ?'
            );
            $update->execute([$this->scope, $subjectHash, $windowDate]);
            $this->database->commit();
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }

        if (random_int(1, 100) === 1) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
            $cleanup = $this->database->prepare('DELETE FROM rate_limit_buckets WHERE window_start < ?');
            $cleanup->execute([$cutoff]);
        }
        return true;
    }
}
