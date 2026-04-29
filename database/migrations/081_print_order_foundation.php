<?php
/**
 * Migration 081: print-order schema foundation for Category G
 * (print order flow rewrite, Cardify v2.0 sprint actions 181-210).
 *
 * Adds:
 *   - print_orders.per_employee_qty JSON                  (action 189)
 *   - print_orders.order_notes TEXT                       (action 198)
 *   - print_orders.quote_expires_at TIMESTAMP             (action 197)
 *   - print_orders.rush_surcharge DECIMAL(10,3)           (action 202)
 *   - print_orders.volume_discount DECIMAL(10,3)          (action 203)
 *   - print_orders.referral_credit DECIMAL(10,3)          (action 204)
 *   - print_orders.qa_proof_url VARCHAR(500)              (action 205)
 *   - print_orders.qa_photo_url VARCHAR(500)              (action 206)
 *   - print_orders.delivery_tracking_url VARCHAR(500)     (action 207)
 *   - print_orders.delivered_photo_url VARCHAR(500)       (action 208)
 *   - print_orders.review_request_sent_at TIMESTAMP       (action 209)
 *   - print_orders.review_id BIGINT UNSIGNED              (action 213 link)
 *   - print_orders.cancellation_reason VARCHAR(255)       (action 194/195)
 *   - print_orders.cancelled_at TIMESTAMP                 (action 194/195)
 *   - print_orders.rejected_at TIMESTAMP                  (action 195)
 *
 *   - print_shop_reviews table (action 213, Category H)
 *   - company_addresses table (action 199 address book)
 *
 * All columns nullable for forward-compat; logic ships in follow-up
 * actions 612-617.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php';
// The above require pulls in addColumnIfMissing() helper from 080.

try {
    $db = Database::getInstance();

    // print_orders column additions
    $cols = [
        'per_employee_qty JSON NULL',
        'order_notes TEXT NULL',
        'quote_expires_at TIMESTAMP NULL',
        'rush_surcharge DECIMAL(10,3) NOT NULL DEFAULT 0.000',
        'volume_discount DECIMAL(10,3) NOT NULL DEFAULT 0.000',
        'referral_credit DECIMAL(10,3) NOT NULL DEFAULT 0.000',
        'qa_proof_url VARCHAR(500) NULL',
        'qa_photo_url VARCHAR(500) NULL',
        'delivery_tracking_url VARCHAR(500) NULL',
        'delivered_photo_url VARCHAR(500) NULL',
        'review_request_sent_at TIMESTAMP NULL',
        'review_id BIGINT UNSIGNED NULL',
        'cancellation_reason VARCHAR(255) NULL',
        'cancelled_at TIMESTAMP NULL',
        'rejected_at TIMESTAMP NULL',
    ];
    foreach ($cols as $def) addColumnIfMissing($db, 'print_orders', $def);

    // print_shop_reviews, one row per order
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS print_shop_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            print_shop_id INT NOT NULL,
            company_id VARCHAR(36) NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            comment TEXT NULL,
            shop_reply TEXT NULL,
            shop_replied_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_order_review (order_id),
            INDEX idx_shop (print_shop_id, created_at),
            INDEX idx_company (company_id),
            CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // company_addresses (saved delivery addresses for repeat orders)
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS company_addresses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            label VARCHAR(80) NULL,
            contact_name VARCHAR(120) NULL,
            phone VARCHAR(32) NULL,
            line1 VARCHAR(255) NOT NULL,
            line2 VARCHAR(255) NULL,
            city VARCHAR(80) NULL,
            state VARCHAR(80) NULL,
            country VARCHAR(2) NOT NULL DEFAULT 'OM',
            postal VARCHAR(20) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL,
            INDEX idx_company (company_id, deleted_at),
            INDEX idx_default (company_id, is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Seed one default address per company from the most-recent
    // print_orders shipping block so repeat-order flows have something
    // to preselect without any admin action.
    $db->exec(<<<'SQL'
        INSERT INTO company_addresses (company_id, contact_name, phone, line1, city, country, postal, is_default)
        SELECT company_id, shipping_name, shipping_phone, shipping_address,
               COALESCE(shipping_city, ''), COALESCE(shipping_country, 'OM'), shipping_postal, 1
        FROM (
            SELECT po.*,
                   ROW_NUMBER() OVER (PARTITION BY company_id ORDER BY created_at DESC) rn
            FROM print_orders po
            WHERE shipping_address IS NOT NULL AND shipping_address <> ''
        ) latest
        WHERE rn = 1
          AND NOT EXISTS (
              SELECT 1 FROM company_addresses a WHERE a.company_id = latest.company_id AND a.is_default = 1
          )
SQL);

    echo "Migration 081: print_orders +15 cols, print_shop_reviews + company_addresses tables created, default addresses seeded\n";
} catch (Exception $e) {
    echo "Migration 081 failed: " . $e->getMessage() . "\n";
    exit(1);
}
