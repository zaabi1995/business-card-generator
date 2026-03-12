<?php
/**
 * Migration 030: Create payments table
 * Unified payment record for ALL Paymob transactions (subscriptions + print orders)
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS payments (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        type ENUM('subscription', 'print_order') NOT NULL,
        reference_id VARCHAR(36) NOT NULL COMMENT 'plan_id for subscriptions, order_id for print orders',
        amount DECIMAL(10,3) NOT NULL,
        currency VARCHAR(3) DEFAULT 'OMR',

        paymob_intention_id VARCHAR(255) NULL,
        paymob_order_id VARCHAR(255) NULL,
        paymob_transaction_id VARCHAR(255) NULL,
        special_reference VARCHAR(255) NULL,
        payment_method VARCHAR(50) NULL COMMENT 'card, omannet, apple_pay',

        status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
        callback_data JSON NULL,

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        INDEX idx_company (company_id),
        INDEX idx_type_ref (type, reference_id),
        INDEX idx_status (status),
        INDEX idx_special_ref (special_reference),
        INDEX idx_paymob_order (paymob_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Migration 030: payments table created successfully\n";
} catch (Exception $e) {
    echo "Migration 030 failed: " . $e->getMessage() . "\n";
    exit(1);
}
