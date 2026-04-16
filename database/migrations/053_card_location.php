<?php
/**
 * Migration 053: Card Location Section
 *
 * Adds "location" section to employee_card_sections so card owners can
 * show an embedded Google Map + "Get Directions" button on their public
 * card. Uses the keyless Google Maps embed (`https://www.google.com/maps?q=<address>&output=embed`)
 * — no API key needed.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $addColumn = function ($name, $ddl) use ($pdo) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'employee_card_sections'
               AND COLUMN_NAME = :col"
        );
        $stmt->execute(['col' => $name]);
        $exists = (int)$stmt->fetchColumn() > 0;
        if ($exists) {
            echo "[053] employee_card_sections.$name already exists - skipped\n";
            return;
        }
        $pdo->exec("ALTER TABLE `employee_card_sections` ADD COLUMN $ddl");
        echo "[053] Added column $name\n";
    };

    $addColumn('location_enabled', "`location_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `offers_enabled`");
    $addColumn('location_address', "`location_address` VARCHAR(512) DEFAULT NULL AFTER `location_enabled`");
    $addColumn('location_lat',     "`location_lat` DECIMAL(10,7) DEFAULT NULL AFTER `location_address`");
    $addColumn('location_lng',     "`location_lng` DECIMAL(10,7) DEFAULT NULL AFTER `location_lat`");
    $addColumn('location_label',   "`location_label` VARCHAR(120) DEFAULT NULL AFTER `location_lng`");

    echo "[053] Migration complete\n";
} catch (Exception $e) {
    echo "[053] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
