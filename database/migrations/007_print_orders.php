<?php
/**
 * Migration 007: Print Orders Table
 * Creates table for tracking physical card print orders
 */

function migration_007_print_orders($pdo) {
    $errors = [];
    
    // Check if table exists
    $tableExists = false;
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'print_orders'");
        $tableExists = $result->rowCount() > 0;
    } catch (PDOException $e) {
        // Table doesn't exist
    }
    
    if (!$tableExists) {
        try {
            $pdo->exec("
                CREATE TABLE print_orders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NULL,
                    employee_id INT NULL,
                    user_id INT NULL,
                    card_template_id INT NULL,
                    
                    -- Order Details
                    quantity INT NOT NULL DEFAULT 100,
                    paper_type VARCHAR(50) DEFAULT 'matte',
                    finish VARCHAR(50) DEFAULT 'standard',
                    card_size VARCHAR(50) DEFAULT 'standard',
                    
                    -- Card Files
                    card_front_url VARCHAR(500) NULL,
                    card_back_url VARCHAR(500) NULL,
                    
                    -- Pricing
                    subtotal DECIMAL(10,2) DEFAULT 0.00,
                    setup_fee DECIMAL(10,2) DEFAULT 0.00,
                    shipping_fee DECIMAL(10,2) DEFAULT 0.00,
                    total DECIMAL(10,2) DEFAULT 0.00,
                    
                    -- Shipping Address
                    shipping_name VARCHAR(255) NULL,
                    shipping_address TEXT NULL,
                    shipping_city VARCHAR(100) NULL,
                    shipping_state VARCHAR(100) NULL,
                    shipping_country VARCHAR(100) NULL,
                    shipping_postal VARCHAR(20) NULL,
                    shipping_phone VARCHAR(50) NULL,
                    
                    -- Order Status
                    status ENUM('pending', 'submitted', 'processing', 'printing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
                    external_order_id VARCHAR(100) NULL,
                    tracking_number VARCHAR(100) NULL,
                    
                    -- API Response
                    api_response TEXT NULL,
                    api_error TEXT NULL,
                    
                    -- Notes
                    notes TEXT NULL,
                    admin_notes TEXT NULL,
                    
                    -- Timestamps
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                    submitted_at TIMESTAMP NULL,
                    shipped_at TIMESTAMP NULL,
                    delivered_at TIMESTAMP NULL,
                    
                    -- Foreign Keys
                    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
                    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
                    
                    -- Indexes
                    INDEX idx_company (company_id),
                    INDEX idx_employee (employee_id),
                    INDEX idx_status (status),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            $errors[] = "Failed to create print_orders table: " . $e->getMessage();
        }
    }
    
    // Ensure settings table exists (for print shop settings)
    $settingsExists = false;
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'settings'");
        $settingsExists = $result->rowCount() > 0;
    } catch (PDOException $e) {
        // Table doesn't exist
    }
    
    if (!$settingsExists) {
        try {
            $pdo->exec("
                CREATE TABLE settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    `key` VARCHAR(100) NOT NULL UNIQUE,
                    value TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_key (`key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            $errors[] = "Failed to create settings table: " . $e->getMessage();
        }
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}
