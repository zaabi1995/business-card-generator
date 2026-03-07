<?php
/**
 * Migration 008: Print Shops Marketplace
 * Creates tables for print shop registration and marketplace functionality
 */

function migration_008_print_shops($pdo) {
    $errors = [];
    
    // Create print_shops table
    $tableExists = false;
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'print_shops'");
        $tableExists = $result->rowCount() > 0;
    } catch (PDOException $e) {
        // Table doesn't exist
    }
    
    if (!$tableExists) {
        try {
            $pdo->exec("
                CREATE TABLE print_shops (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    
                    -- Basic Info
                    name VARCHAR(255) NOT NULL,
                    slug VARCHAR(100) UNIQUE,
                    description TEXT NULL,
                    logo_url VARCHAR(500) NULL,
                    
                    -- Contact
                    email VARCHAR(255) NOT NULL,
                    phone VARCHAR(50) NULL,
                    website VARCHAR(255) NULL,
                    
                    -- Address
                    address TEXT NULL,
                    city VARCHAR(100) NULL,
                    state VARCHAR(100) NULL,
                    country VARCHAR(100) NULL,
                    postal_code VARCHAR(20) NULL,
                    
                    -- Owner/User Link
                    user_id INT NULL,
                    
                    -- Services & Capabilities
                    services JSON NULL,
                    paper_types JSON NULL,
                    finishes JSON NULL,
                    card_sizes JSON NULL,
                    
                    -- Pricing
                    pricing JSON NULL,
                    currency VARCHAR(3) DEFAULT 'USD',
                    min_order_quantity INT DEFAULT 50,
                    
                    -- Turnaround
                    turnaround_days INT DEFAULT 5,
                    express_available BOOLEAN DEFAULT FALSE,
                    express_days INT DEFAULT 2,
                    express_fee DECIMAL(10,2) DEFAULT 0.00,
                    
                    -- Shipping
                    shipping_zones JSON NULL,
                    free_shipping_threshold DECIMAL(10,2) NULL,
                    
                    -- API Integration (optional)
                    api_enabled BOOLEAN DEFAULT FALSE,
                    api_endpoint VARCHAR(500) NULL,
                    api_key VARCHAR(255) NULL,
                    webhook_secret VARCHAR(255) NULL,
                    
                    -- Status
                    status ENUM('pending', 'active', 'suspended', 'inactive') DEFAULT 'pending',
                    featured BOOLEAN DEFAULT FALSE,
                    verified BOOLEAN DEFAULT FALSE,
                    rating DECIMAL(3,2) DEFAULT 0.00,
                    total_orders INT DEFAULT 0,
                    
                    -- Metadata
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                    approved_at TIMESTAMP NULL,
                    approved_by INT NULL,
                    
                    -- Indexes
                    INDEX idx_status (status),
                    INDEX idx_featured (featured),
                    INDEX idx_country (country),
                    INDEX idx_user (user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            $errors[] = "Failed to create print_shops table: " . $e->getMessage();
        }
    }
    
    // Add print_shop_id to print_orders table
    try {
        $result = $pdo->query("SHOW COLUMNS FROM print_orders LIKE 'print_shop_id'");
        if ($result->rowCount() == 0) {
            $pdo->exec("ALTER TABLE print_orders ADD COLUMN print_shop_id INT NULL AFTER id");
            $pdo->exec("ALTER TABLE print_orders ADD INDEX idx_print_shop (print_shop_id)");
        }
    } catch (PDOException $e) {
        $errors[] = "Failed to add print_shop_id to print_orders: " . $e->getMessage();
    }
    
    // Add 'print_shop' to user roles if users table exists
    try {
        $result = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
        if ($result->rowCount() > 0) {
            // Check current enum values
            $stmt = $pdo->query("SHOW COLUMNS FROM users WHERE Field = 'role'");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (strpos($row['Type'], 'print_shop') === false) {
                // Need to alter the enum to include print_shop
                $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'company', 'user', 'print_shop') DEFAULT 'user'");
            }
        }
    } catch (PDOException $e) {
        // Users table might not have role column, ignore
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}
