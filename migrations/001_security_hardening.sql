CREATE TABLE IF NOT EXISTS rate_limit_buckets (
 scope VARCHAR(32) NOT NULL,
 subject_hash CHAR(64) NOT NULL,
 window_start DATETIME NOT NULL,
 request_count INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(scope, subject_hash, window_start),
 INDEX idx_rate_limit_cleanup(window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE app_settings MODIFY value MEDIUMTEXT NULL;
ALTER TABLE conversations MODIFY title TEXT NOT NULL;
