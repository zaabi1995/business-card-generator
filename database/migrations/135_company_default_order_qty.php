<?php
// database/migrations/135_company_default_order_qty.php
// Per-tenant default card-order quantity. The employee portal quantity
// field falls back to this value when the visitor does not pick one
// (e.g. new-card requests, where the quantity selector stays hidden).
// OHB's standard order is 200 pcs, so it is backfilled explicitly below;
// every other tenant keeps the column default of 200.
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();

    $columnExists = $db->fetchOne(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies' AND COLUMN_NAME = 'default_order_qty'"
    );

    if (!$columnExists) {
        $db->exec("ALTER TABLE companies ADD COLUMN default_order_qty INT NOT NULL DEFAULT 200");
        echo "Migration 135: added companies.default_order_qty (default 200)\n";
    } else {
        echo "Migration 135: companies.default_order_qty already exists, skipping\n";
    }

    $db->exec("UPDATE companies SET default_order_qty = 200 WHERE id = 'a0b10000-0000-0000-0000-00000000b001'");
    echo "Migration 135: OHB default_order_qty set to 200\n";
} catch (Exception $e) {
    echo "Migration 135 failed: " . $e->getMessage() . "\n";
    exit(1);
}
