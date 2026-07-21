<?php
// database/migrations/136_card_requests_print_order_id.php
// Links an approved card request to the BHD print order it placed, so the
// approve chain is idempotent (never double-places on a repeat approve).
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    $exists = $db->fetchOne(
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_requests'
           AND COLUMN_NAME = 'print_order_id'"
    );
    if ((int)($exists['n'] ?? 0) === 0) {
        $db->exec("ALTER TABLE card_requests ADD COLUMN print_order_id VARCHAR(36) NULL");
        echo "Migration 136: card_requests.print_order_id added\n";
    } else {
        echo "Migration 136: print_order_id already present, skipped\n";
    }
} catch (Exception $e) {
    echo "Migration 136 failed: " . $e->getMessage() . "\n";
    exit(1);
}
