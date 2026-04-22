<?php
/**
 * Migration 094: saved Paymob cards for MOTO + repeat charges.
 *
 * When a customer ticks "save this card" on Paymob checkout (or when
 * the intent was created with save_card_token=1), Paymob returns a
 * reusable card_token on the webhook. We persist it so repeat
 * charges (subscription renewals, MOTO phone orders, one-click
 * top-ups) don't need the customer physically present.
 *
 * PCI scope stays at Paymob. We only store:
 *   - last_four + brand (for UI)
 *   - token (opaque to us, accepted by Paymob create-intent)
 *   - holder_name (display)
 *
 * No full PAN, CVV, or expiry here.
 */
require_once __DIR__ . '/../../config.php';

try {
    Database::getInstance()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS saved_cards (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            paymob_token VARCHAR(255) NOT NULL,
            brand VARCHAR(24) NULL,
            last_four CHAR(4) NULL,
            holder_name VARCHAR(120) NULL,
            expiry_month TINYINT UNSIGNED NULL,
            expiry_year SMALLINT UNSIGNED NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            last_used_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL,
            UNIQUE KEY uk_token (paymob_token),
            INDEX idx_company_active (company_id, deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    echo "Migration 094: saved_cards table ready\n";
} catch (Exception $e) {
    echo "Migration 094 failed: " . $e->getMessage() . "\n";
    exit(1);
}
