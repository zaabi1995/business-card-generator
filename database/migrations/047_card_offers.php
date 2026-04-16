<?php
/**
 * Migration 047: Card Offers / Coupons
 *
 * Adds the "offers" public card section — time-limited promotional offers
 * the cardholder can attach to their digital card (e.g. "10% off — show this
 * card"). Each offer tracks redemption count via /api/offer/redeem.php.
 *
 * Idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_card_offers (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        company_id VARCHAR(36) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        discount_label VARCHAR(64) DEFAULT NULL,
        valid_from DATE NULL,
        valid_until DATE NULL,
        badge_color VARCHAR(16) DEFAULT '#009bc1',
        redemption_count INT NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        position INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        INDEX idx_employee_position (employee_id, position),
        INDEX idx_employee_validity (employee_id, enabled, valid_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add offers_enabled to master sections row (idempotent — skip if exists)
    $colExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'employee_card_sections'
           AND COLUMN_NAME = 'offers_enabled'"
    )->fetchColumn();
    if (!$colExists) {
        $pdo->exec("ALTER TABLE employee_card_sections
                    ADD COLUMN offers_enabled TINYINT(1) NOT NULL DEFAULT 0
                    AFTER lead_form_email");
    }

    // Extend card_events ENUM with 'offer_redeem' (idempotent — only ALTER if missing)
    $col = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'card_events'
           AND COLUMN_NAME = 'event_type'"
    )->fetchColumn();
    if ($col && strpos($col, "'offer_redeem'") === false) {
        $pdo->exec("ALTER TABLE card_events MODIFY COLUMN event_type
            ENUM('view','click_phone','click_mobile','click_whatsapp','click_email',
                 'click_website','click_map','click_social','save_contact','wallet_add',
                 'qr_scan','offer_redeem') NOT NULL");
        echo "[047] card_events.event_type extended with offer_redeem\n";
    }

    echo "[047] employee_card_offers ready\n";
    echo "[047] Migration complete\n";
} catch (Exception $e) {
    fwrite(STDERR, "[047] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
