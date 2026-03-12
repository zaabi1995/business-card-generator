<?php
/**
 * Migration 032: Add payment_method and payment_id columns to print_orders
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    if (!$db->columnExists('print_orders', 'payment_method')) {
        $db->exec("ALTER TABLE print_orders
            ADD COLUMN payment_method ENUM('online', 'credit') NULL AFTER payment_status,
            ADD COLUMN payment_id VARCHAR(36) NULL AFTER payment_method");
        echo "Migration 032: payment_method and payment_id columns added to print_orders\n";
    } else {
        echo "Migration 032: columns already exist, skipping\n";
    }
} catch (Exception $e) {
    echo "Migration 032 failed: " . $e->getMessage() . "\n";
    exit(1);
}
