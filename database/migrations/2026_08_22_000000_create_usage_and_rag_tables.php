<?php
// database/migrations/2026_08_22_000000_create_usage_and_rag_tables.php

use Core\Database;

function runUsageAndRagMigration() {
    $db = Database::getConnection();
    
    // 1. Token & Usage Analytics table
    $db->exec("
        CREATE TABLE IF NOT EXISTS ai_usage_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            bot_id INT UNSIGNED NULL,
            model_name VARCHAR(64) NOT NULL,
            prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            estimated_cost DECIMAL(10, 6) NOT NULL DEFAULT 0.000000,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. RAG Documents with Role-based access
    $db->exec("
        CREATE TABLE IF NOT EXISTS rag_documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            extracted_content LONGTEXT NOT NULL,
            min_role_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
            uploaded_by INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_min_role (min_role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

runUsageAndRagMigration();
