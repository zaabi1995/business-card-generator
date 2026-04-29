<?php
/**
 * Migration 078: otp_codes table.
 *
 * Short-lived 6-digit OTP codes used by the OTP-first registration flow
 * (actions 112-113 of the Cardify v2.0 sprint) and future passwordless
 * logins. Codes are hashed at rest (SHA-256); the plain 6 digits only
 * live in the WhatsApp/email message.
 *
 * Throttling is enforced separately via RateLimiter::check() on the
 * send endpoint (3 per hour per phone, 10 per day per IP).
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS otp_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(120) NOT NULL COMMENT 'E.164 phone or email',
            channel ENUM('whatsapp','email') NOT NULL,
            code_hash CHAR(64) NOT NULL,
            purpose VARCHAR(40) NOT NULL DEFAULT 'signup',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            consumed_at TIMESTAMP NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            INDEX idx_ident (identifier, purpose, consumed_at),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    echo "Migration 078: otp_codes table created\n";
} catch (Exception $e) {
    echo "Migration 078 failed: " . $e->getMessage() . "\n";
    exit(1);
}
