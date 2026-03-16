<?php
/**
 * Migration 035: Add capacity and availability settings to print_shops
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    // Add capacity columns to print_shops
    $db->exec("ALTER TABLE print_shops
        ADD COLUMN IF NOT EXISTS working_hours JSON NULL COMMENT 'Working hours per day of week',
        ADD COLUMN IF NOT EXISTS max_daily_orders INT NOT NULL DEFAULT 0 COMMENT '0 = unlimited',
        ADD COLUMN IF NOT EXISTS capacity_notes TEXT NULL COMMENT 'Additional capacity/availability notes',
        ADD COLUMN IF NOT EXISTS accepting_orders TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Toggle order acceptance'
    ");

    echo "Migration 035: Added capacity columns to print_shops\n";
} catch (Exception $e) {
    echo "Migration 035 failed: " . $e->getMessage() . "\n";
    exit(1);
}
