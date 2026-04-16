<?php
/**
 * Migration 055: Branded Link Shortener
 *
 * Per-company shortened URL service (cardify.om/s/<slug> → destination).
 * Tracks click counts and logs each hit into card_events with the new
 * 'short_link_click' event_type (ENUM extended idempotently).
 *
 * Idempotent — safe to run multiple times.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $pdo->exec("CREATE TABLE IF NOT EXISTS short_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        slug VARCHAR(20) NOT NULL UNIQUE,
        destination VARCHAR(1024) NOT NULL,
        title VARCHAR(200) NULL,
        click_count INT NOT NULL DEFAULT 0,
        expires_at DATETIME NULL,
        created_by_user_id VARCHAR(36) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_slug (company_id, slug),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "[055] short_links table ready\n";

    // Extend card_events ENUM with 'short_link_click' (idempotent)
    $col = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'card_events'
           AND COLUMN_NAME = 'event_type'"
    )->fetchColumn();
    if ($col && strpos($col, "'short_link_click'") === false) {
        $pdo->exec("ALTER TABLE card_events MODIFY COLUMN event_type
            ENUM('view','click_phone','click_mobile','click_whatsapp','click_email',
                 'click_website','click_map','click_social','save_contact','wallet_add',
                 'qr_scan','offer_redeem','short_link_click') NOT NULL");
        echo "[055] card_events.event_type extended with short_link_click\n";
    } else {
        echo "[055] card_events.event_type already has short_link_click (or card_events missing) — skipping\n";
    }

    echo "[055] Migration complete\n";
} catch (Exception $e) {
    fwrite(STDERR, "[055] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
