<?php
/**
 * Migration 082: print-shop marketplace schema foundation for
 * Category H (Cardify v2.0 sprint actions 211-235).
 *
 * Adds to print_shops:
 *   - lat / lng DECIMAL (action 219, distance)
 *   - bhd_verified_at TIMESTAMP (action 226, trust badge)
 *   - base_price_per_card DECIMAL(10,3) (action 218)
 *   - hours_json JSON (action 223, open hours + holidays)
 *   - specializations JSON (action 234, cards-only / premium / etc.)
 *   - coverage_wilayats JSON (action 233, Oman coverage map)
 *
 * New tables:
 *   - print_shop_photos (action 221, up to 10 per shop)
 *   - print_shop_kyc (action 228, CR / IBAN / owner ID)
 *   - print_shop_payouts (action 229, monthly ERP payouts)
 *   - print_shop_disputes (action 230, mediation queue)
 *   - print_shop_blocks (action 231, shop declines specific companies)
 *
 * UI work ships in follow-up actions 638-657.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php'; // addColumnIfMissing()

try {
    $db = Database::getInstance();

    // print_shops column additions
    $cols = [
        'lat DECIMAL(10,7) NULL',
        'lng DECIMAL(10,7) NULL',
        'bhd_verified_at TIMESTAMP NULL',
        'base_price_per_card DECIMAL(10,3) NULL',
        'hours_json JSON NULL',
        'specializations JSON NULL',
        'coverage_wilayats JSON NULL',
    ];
    foreach ($cols as $def) addColumnIfMissing($db, 'print_shops', $def);

    // Photos, up to 10 per shop. sort_order drives display.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS print_shop_photos (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            print_shop_id INT NOT NULL,
            photo_path VARCHAR(500) NOT NULL,
            caption VARCHAR(255) NULL,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL,
            INDEX idx_shop (print_shop_id, deleted_at, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // KYC docs + bank info, one row per shop.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS print_shop_kyc (
            print_shop_id INT PRIMARY KEY,
            cr_number VARCHAR(80) NULL,
            cr_file_path VARCHAR(500) NULL,
            owner_name VARCHAR(160) NULL,
            owner_id_file_path VARCHAR(500) NULL,
            bank_name VARCHAR(120) NULL,
            iban VARCHAR(64) NULL,
            iban_verified_at TIMESTAMP NULL,
            verified_at TIMESTAMP NULL,
            verified_by VARCHAR(36) NULL,
            rejection_reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Payouts. One row per billing period per shop.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS print_shop_payouts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            print_shop_id INT NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            gross_amount DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            cardify_commission DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            net_payout DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            currency VARCHAR(3) NOT NULL DEFAULT 'OMR',
            status ENUM('draft','pending','paid','failed') NOT NULL DEFAULT 'draft',
            erp_invoice_id VARCHAR(36) NULL,
            paid_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_period (print_shop_id, period_start, period_end),
            INDEX idx_status (status, period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Disputes, one per order.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS print_shop_disputes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            print_shop_id INT NOT NULL,
            company_id VARCHAR(36) NOT NULL,
            opened_by ENUM('company','shop') NOT NULL,
            reason VARCHAR(120) NOT NULL,
            body TEXT NULL,
            status ENUM('open','in_review','resolved','rejected') NOT NULL DEFAULT 'open',
            resolution TEXT NULL,
            mediator_id VARCHAR(36) NULL,
            opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL,
            UNIQUE KEY uniq_order_open (order_id, status),
            INDEX idx_shop (print_shop_id, status),
            INDEX idx_company (company_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Shop blocks, shop declines specific companies.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS print_shop_blocks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            print_shop_id INT NOT NULL,
            company_id VARCHAR(36) NOT NULL,
            reason VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pair (print_shop_id, company_id),
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    echo "Migration 082: marketplace foundation ready (print_shops +7 cols, 5 new tables)\n";
} catch (Exception $e) {
    echo "Migration 082 failed: " . $e->getMessage() . "\n";
    exit(1);
}
