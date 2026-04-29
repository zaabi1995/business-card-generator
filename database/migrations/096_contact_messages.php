<?php
/**
 * Migration 096: contact_messages (Cat U action 507).
 *
 * Persists every /contact form submission so nothing gets lost when
 * the outbound email queue has a hiccup. Super-admin reads via
 * /admin/super/feedback — queued in a follow-up. Indexed for
 * "replied = NULL AND created_at > …" lookups (open inbox query).
 */
require_once __DIR__ . '/../../config.php';

try {
    Database::getInstance()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS contact_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(160) NOT NULL,
            subject ENUM('general','support','sales','partner','feedback') NOT NULL DEFAULT 'general',
            message TEXT NOT NULL,
            locale ENUM('en','ar') NOT NULL DEFAULT 'en',
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            replied_at TIMESTAMP NULL,
            replied_by VARCHAR(36) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_open_inbox (replied_at, created_at),
            INDEX idx_email (email),
            INDEX idx_subject (subject)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "Migration 096: contact_messages table ready\n";
} catch (Exception $e) {
    echo "Migration 096 failed: " . $e->getMessage() . "\n";
    exit(1);
}
