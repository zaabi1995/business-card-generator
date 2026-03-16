<?php
/**
 * Migration 036: Add deposit/partial payment support to print_orders
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $db->exec("ALTER TABLE print_orders
        ADD COLUMN IF NOT EXISTS deposit_percent DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Deposit % collected upfront (0 = full payment)',
        ADD COLUMN IF NOT EXISTS deposit_amount DECIMAL(10,3) NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS balance_due DECIMAL(10,3) NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS deposit_paid_at TIMESTAMP NULL,
        ADD COLUMN IF NOT EXISTS balance_paid_at TIMESTAMP NULL
    ");

    echo "Migration 036: Deposit/partial payment columns added\n";
} catch (Exception $e) {
    echo "Migration 036 failed: " . $e->getMessage() . "\n";
    exit(1);
}
