<?php
/**
 * Migration 031: Create credit_accounts and credit_transactions tables
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS credit_accounts (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        print_shop_id INT NOT NULL,

        credit_limit DECIMAL(10,3) DEFAULT 0.000,
        balance_used DECIMAL(10,3) DEFAULT 0.000,
        payment_terms ENUM('net15', 'net30', 'net60', 'net90') DEFAULT 'net30',

        status ENUM('pending', 'approved', 'suspended', 'rejected') DEFAULT 'pending',
        requested_limit DECIMAL(10,3) NULL COMMENT 'What company asked for',
        request_notes TEXT NULL,

        approved_by VARCHAR(36) NULL,
        approved_at TIMESTAMP NULL,
        rejected_reason TEXT NULL,

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        UNIQUE KEY uk_company_shop (company_id, print_shop_id),
        INDEX idx_print_shop (print_shop_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS credit_transactions (
        id VARCHAR(36) PRIMARY KEY,
        credit_account_id VARCHAR(36) NOT NULL,
        order_id INT NULL COMMENT 'print_orders.id if charge/refund',

        type ENUM('charge', 'payment', 'adjustment', 'refund') NOT NULL,
        amount DECIMAL(10,3) NOT NULL COMMENT 'Positive = increases balance_used',
        balance_after DECIMAL(10,3) NOT NULL,

        notes TEXT NULL,
        recorded_by VARCHAR(36) NULL COMMENT 'User who recorded (for manual payments)',

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_account (credit_account_id),
        INDEX idx_order (order_id),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "Migration 031: credit_accounts and credit_transactions tables created successfully\n";
} catch (Exception $e) {
    echo "Migration 031 failed: " . $e->getMessage() . "\n";
    exit(1);
}
