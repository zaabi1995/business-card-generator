<?php
/**
 * Migration 107: print_shops.is_internal_provider
 *
 * Flag that unlocks the "Browse Clients" + order-on-behalf flow for
 * print shops that act as in-house providers (e.g. BHD ordering on
 * behalf of any Cardify company). Default 0 = no behavior change for
 * other shops.
 *
 * Also seeds is_internal_provider=1 on the BHD shop (id=2).
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $hasCol = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'print_shops'
           AND column_name = 'is_internal_provider'"
    )->fetchColumn();

    if ($hasCol === 0) {
        $pdo->exec(
            "ALTER TABLE print_shops
             ADD COLUMN is_internal_provider TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'When 1, unlocks browse-all-clients + order-on-behalf flow.'"
        );
        echo "[107] Added print_shops.is_internal_provider\n";
    } else {
        echo "[107] is_internal_provider already exists\n";
    }

    $bhdId = (int) $pdo->query(
        "SELECT id FROM print_shops WHERE slug = 'bhd-printing-amp-designing' LIMIT 1"
    )->fetchColumn();
    if ($bhdId > 0) {
        $pdo->prepare("UPDATE print_shops SET is_internal_provider = 1 WHERE id = ?")
            ->execute([$bhdId]);
        echo "[107] Set is_internal_provider=1 on BHD shop (id=$bhdId)\n";
    } else {
        echo "[107] BHD shop not found, skipping seed\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[107] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
