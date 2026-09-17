<?php
/**
 * Migration 162: remember whether the employee asked for a QR code.
 *
 * The portal tickbox decided the preview and the emailed PDF, and then nothing
 * kept it. When the purchase order arrived, production re-rendered the card
 * with the QR forced on, so a card approved without one was printed with one.
 * The choice now lives on the request, which is the only place that can answer
 * the question weeks later.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    if (!$db->columnExists('card_requests', 'include_qr')) {
        $db->exec("ALTER TABLE `card_requests` ADD COLUMN `include_qr` TINYINT(1) NULL");
        echo "Migration 162: card_requests.include_qr added\n";
    } else {
        echo "Migration 162: card_requests.include_qr already present\n";
    }
    echo "Migration 162: done\n";
} catch (Exception $e) {
    echo "Migration 162 failed: " . $e->getMessage() . "\n";
    exit(1);
}
