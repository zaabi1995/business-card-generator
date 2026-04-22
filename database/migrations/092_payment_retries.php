<?php
/**
 * Migration 092: payment_retries queue (Cat S action 455).
 *
 * One pending row per failed Paymob payment. The worker re-creates
 * the intent and WhatsApp/emails a fresh checkout link to the
 * customer on an exponential schedule over ~7 days. After the ladder
 * is exhausted the row flips to 'exhausted' and a human takes over.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS payment_retries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            original_payment_id VARCHAR(36) NOT NULL,
            target_type ENUM('subscription','print_order','card_order') NOT NULL,
            company_id VARCHAR(36) NOT NULL,
            reference_id VARCHAR(36) NOT NULL,
            amount DECIMAL(10,3) NOT NULL,
            currency VARCHAR(3) NOT NULL DEFAULT 'OMR',
            billing_cycle VARCHAR(20) NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            last_attempt_at TIMESTAMP NULL,
            next_retry_at TIMESTAMP NOT NULL,
            last_checkout_url VARCHAR(500) NULL,
            status ENUM('pending','succeeded','exhausted','cancelled') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_orig (original_payment_id),
            INDEX idx_due (status, next_retry_at),
            INDEX idx_company (company_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "Migration 092: payment_retries table ready\n";
} catch (Exception $e) {
    echo "Migration 092 failed: " . $e->getMessage() . "\n";
    exit(1);
}
