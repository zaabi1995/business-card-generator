<?php
/**
 * Migration 058: Card Product Catalog
 *
 * Adds a mini product catalog section to public employee cards — each card
 * can display a small list of products (image, title, price, description)
 * with an "Order via WhatsApp" CTA per product.
 *
 * - Creates `employee_card_products` table.
 * - Adds `products_enabled` toggle to `employee_card_sections`.
 * - Extends `card_events.event_type` ENUM with `product_order_click`.
 *
 * Idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // 1) Products table
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_card_products (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        company_id VARCHAR(36) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(10,3) NOT NULL DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT 'OMR',
        image_path VARCHAR(512) DEFAULT NULL,
        whatsapp_message VARCHAR(500) DEFAULT NULL,
        position INT NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
        INDEX idx_employee_pos_enabled (employee_id, position, enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[058] employee_card_products ready\n";

    // 2) Master toggle column
    $colExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'employee_card_sections'
           AND COLUMN_NAME  = 'products_enabled'"
    )->fetchColumn();
    if (!$colExists) {
        $pdo->exec("ALTER TABLE employee_card_sections
                    ADD COLUMN products_enabled TINYINT(1) NOT NULL DEFAULT 0");
        echo "[058] employee_card_sections.products_enabled added\n";
    } else {
        echo "[058] employee_card_sections.products_enabled already exists - skipped\n";
    }

    // 3) Extend card_events ENUM with product_order_click
    $col = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'card_events'
           AND COLUMN_NAME  = 'event_type'"
    )->fetchColumn();
    if ($col && strpos($col, "'product_order_click'") === false) {
        $pdo->exec("ALTER TABLE card_events MODIFY COLUMN event_type
            ENUM('view','click_phone','click_mobile','click_whatsapp','click_email',
                 'click_website','click_map','click_social','save_contact','wallet_add',
                 'qr_scan','offer_redeem','product_order_click') NOT NULL");
        echo "[058] card_events.event_type extended with product_order_click\n";
    } else {
        echo "[058] card_events.event_type already contains product_order_click - skipped\n";
    }

    echo "[058] Migration complete\n";
} catch (Exception $e) {
    fwrite(STDERR, "[058] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
