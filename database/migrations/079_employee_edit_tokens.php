<?php
/**
 * Migration 079: employee_edit_tokens table.
 *
 * Magic-link tokens for the passwordless employee self-service edit
 * flow (Cardify v2.0 Category E, actions 126-150). One row per active
 * token; admins can mint a new token for an employee at any time
 * (invalidates the previous one for that employee).
 *
 * Tokens are 40-char hex, SHA-256 hashed at rest. Plain token only
 * appears in the invite message.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS employee_edit_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            last_used_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            created_by VARCHAR(36) NULL,
            ip VARCHAR(45) NULL,
            INDEX idx_employee (employee_id, revoked_at),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    echo "Migration 079: employee_edit_tokens table created\n";
} catch (Exception $e) {
    echo "Migration 079 failed: " . $e->getMessage() . "\n";
    exit(1);
}
