<?php
/**
 * Migration 091: card_credit_ledger (Cat S action 450).
 *
 * Tracks every change to companies.card_credits. One row per generate
 * charge, purchase, refund or manual adjustment. UNIQUE on
 * (company_id, employee_id, reason) so the generate-charge is
 * idempotent: re-generating the same employee never double-charges.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS card_credit_ledger (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            employee_id VARCHAR(36) NULL,
            delta INT NOT NULL,
            balance_after INT NOT NULL,
            reason VARCHAR(40) NOT NULL,
            ref_id VARCHAR(120) NULL,
            notes VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_generate_idem (company_id, employee_id, reason),
            INDEX idx_company_created (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "Migration 091: card_credit_ledger table ready\n";
} catch (Exception $e) {
    echo "Migration 091 failed: " . $e->getMessage() . "\n";
    exit(1);
}
