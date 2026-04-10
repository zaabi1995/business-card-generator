<?php
/**
 * Migration 040 — Onboarding email queue
 *
 * Creates email_queue table for scheduled/drip emails (onboarding sequence).
 * Cron job processes pending emails whose send_at has passed.
 */

require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $pdo->exec("CREATE TABLE IF NOT EXISTS email_queue (
        id          VARCHAR(36) PRIMARY KEY,
        company_id  VARCHAR(36) NOT NULL,
        user_email  VARCHAR(255) NOT NULL,
        template    VARCHAR(100) NOT NULL,
        template_data TEXT NULL COMMENT 'JSON-encoded template variables',
        send_at     TIMESTAMP NOT NULL COMMENT 'Scheduled send time',
        sent_at     TIMESTAMP NULL COMMENT 'Actual send time',
        status      ENUM('pending', 'sent', 'failed', 'cancelled') DEFAULT 'pending',
        attempts    TINYINT UNSIGNED DEFAULT 0,
        last_error  TEXT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status_send (status, send_at),
        INDEX idx_company (company_id),
        INDEX idx_user_template (user_email, template)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 040: email_queue table created\n";

    echo "Migration 040 complete\n";

} catch (Exception $e) {
    echo "Migration 040 failed: " . $e->getMessage() . "\n";
    exit(1);
}
