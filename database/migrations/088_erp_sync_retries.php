<?php
/**
 * Migration 088: erp_sync_retries queue (Category S action 443).
 *
 * One row per failing print-order ERP sync. Holds the original
 * recordPayment() arguments so a background worker can replay the
 * call with exponential backoff until success or exhaustion.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS erp_sync_retries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            payment_method VARCHAR(40) NOT NULL DEFAULT '',
            payment_ref    VARCHAR(120) NOT NULL DEFAULT '',
            payment_notes  TEXT NULL,
            recorded_by    VARCHAR(36) NOT NULL DEFAULT '',
            attempts       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error     TEXT NULL,
            last_tried_at  TIMESTAMP NULL DEFAULT NULL,
            next_retry_at  TIMESTAMP NOT NULL,
            status         ENUM('pending','succeeded','exhausted','cancelled') NOT NULL DEFAULT 'pending',
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at     TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_order_pending (order_id, status),
            INDEX idx_due (status, next_retry_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "Migration 088: erp_sync_retries table ready\n";
} catch (Exception $e) {
    echo "Migration 088 failed: " . $e->getMessage() . "\n";
    exit(1);
}
