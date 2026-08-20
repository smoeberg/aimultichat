<?php
// database/migrations/2026_08_21_000000_create_roles_and_capabilities_tables.php

use Core\Database;

/**
 * Migration for creating roles and capabilities tables
 * This is a simple PHP migration for the custom framework
 */
function runRolesMigration() {
    $db = Database::getConnection();
    
    // 1. Roller
    $db->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL UNIQUE,
            name VARCHAR(64) NOT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Capabilities
    $db->exec("
        CREATE TABLE IF NOT EXISTS capabilities (
            id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 3. Role-Capability matrix
    $db->exec("
        CREATE TABLE IF NOT EXISTS role_capabilities (
            role_id TINYINT UNSIGNED NOT NULL,
            capability_id SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, capability_id),
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            FOREIGN KEY (capability_id) REFERENCES capabilities(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 4. Organizations table (if not exists)
    $db->exec("
        CREATE TABLE IF NOT EXISTS organizations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            owner_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 5. Organization members
    $db->exec("
        CREATE TABLE IF NOT EXISTS organization_members (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            role_id TINYINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (organization_id, user_id),
            FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 6. Invitations table
    $db->exec("
        CREATE TABLE IF NOT EXISTS invitations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id INT UNSIGNED NOT NULL,
            email VARCHAR(255) NOT NULL,
            role_id TINYINT UNSIGNED NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    echo "Roles and capabilities tables created successfully.\n";
}

// Check if this is being run directly
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../../bootstrap.php';
    runRolesMigration();
}
