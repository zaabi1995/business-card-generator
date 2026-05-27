<?php
/**
 * Lead-capture table for logo downloads. Companies wanting to grab
 * a brand asset hand over a phone OR email first, get a 90-day
 * unlock cookie, then can pull every format on every brand for
 * the cookie lifetime.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS logo_leads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NULL,
        format VARCHAR(16) NULL,
        name VARCHAR(120) NULL,
        phone VARCHAR(32) NULL,
        email VARCHAR(160) NULL,
        cookie_id CHAR(32) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        user_agent_hash CHAR(64) NULL,
        referrer VARCHAR(512) NULL,
        consent_marketing TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_phone (phone),
        INDEX idx_email (email),
        INDEX idx_company (company_id),
        INDEX idx_cookie (cookie_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Tie each download row back to the lead that unlocked it
    try {
        $db->exec("ALTER TABLE logo_downloads ADD COLUMN unlock_cookie_id CHAR(32) NULL AFTER referrer");
        $db->exec("ALTER TABLE logo_downloads ADD INDEX idx_unlock_cookie (unlock_cookie_id)");
    } catch (Throwable $e) {
        // already exists, fine
    }

    echo "Migration 112: logo_leads created\n";
} catch (Exception $e) {
    echo "Migration 112 failed: " . $e->getMessage() . "\n";
    exit(1);
}
