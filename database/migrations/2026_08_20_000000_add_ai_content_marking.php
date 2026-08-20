<?php
// database/migrations/2026_08_20_000000_add_ai_content_marking.php

use Core\Database;

// This is a simple PHP migration for the custom framework
// It will be executed via a migration runner or manually

function runMigration() {
    $db = Database::getConnection();
    
    // Create ai_requests table
    $db->exec("
        CREATE TABLE IF NOT EXISTS ai_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(64) NOT NULL UNIQUE,
            organization_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            conversation_id BIGINT UNSIGNED NULL,
            provider VARCHAR(64) NOT NULL,
            model VARCHAR(150) NOT NULL,
            provider_request_id VARCHAR(128) NULL,
            watermark_type VARCHAR(32) NULL,
            is_ai_generated TINYINT(1) NOT NULL DEFAULT 1,
            content_hash VARCHAR(64) NULL,
            policy_version VARCHAR(16) NOT NULL DEFAULT '1.0.0',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ai_requests_organization (organization_id),
            INDEX idx_ai_requests_user (user_id),
            INDEX idx_ai_requests_conversation (conversation_id),
            INDEX idx_ai_requests_request_id (request_id),
            INDEX idx_ai_requests_created (created_at),
            FOREIGN KEY (organization_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Create ai_response_contents table
    $db->exec("
        CREATE TABLE IF NOT EXISTS ai_response_contents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id VARCHAR(64) NOT NULL UNIQUE,
            content LONGTEXT NOT NULL,
            prompt_hash VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ai_contents_request_id (request_id),
            FOREIGN KEY (request_id) REFERENCES ai_requests(request_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Create audit_events table for copy logging
    $db->exec("
        CREATE TABLE IF NOT EXISTS audit_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(64) NOT NULL,
            request_id VARCHAR(64) NULL,
            organization_id INT UNSIGNED NULL,
            metadata TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_events_type (event_type),
            INDEX idx_audit_events_request (request_id),
            INDEX idx_audit_events_organization (organization_id),
            INDEX idx_audit_events_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    echo "Migration executed successfully.\n";
}

// Check if this is being run directly
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../../bootstrap.php';
    runMigration();
}
