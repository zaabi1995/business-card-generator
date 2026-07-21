<?php
// database/migrations/134_admin_approval_tokens.php
// Scoped admin-approval magic links: a one-tap token that lets an admin
// approve a pending request (leave-company, edit, etc) without logging
// in, scoped strictly to the company + request it was minted for.
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS admin_approval_tokens (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        request_id VARCHAR(36) NOT NULL,
        admin_email VARCHAR(255) NOT NULL,
        token CHAR(40) NOT NULL UNIQUE,
        expires_at TIMESTAMP NULL,
        used_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_request (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 134: admin_approval_tokens created\n";
} catch (Exception $e) {
    echo "Migration 134 failed: " . $e->getMessage() . "\n";
    exit(1);
}
