<?php
/**
 * Migration 009: Complete SaaS Schema
 * Adds currency support, multi-currency pricing, unified orders, and audit logs
 * Safe to run multiple times (idempotent)
 */
function migration_009_saas_complete($pdo) {
    $errors = [];
    
    // Helper function to check if column exists
    $columnExists = function($table, $column) use ($pdo) {
        try {
            $result = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $result && $result->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Helper function to check if table exists
    $tableExists = function($table) use ($pdo) {
        try {
            $result = $pdo->query("SHOW TABLES LIKE '$table'");
            return $result && $result->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Helper function to safely execute SQL
    $safeExec = function($sql, $ignoreErrors = ['Duplicate', 'already exists', '1060', '1061', '1050']) use ($pdo, &$errors) {
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            foreach ($ignoreErrors as $ignore) {
                if (strpos($msg, $ignore) !== false) {
                    return true; // Ignored error
                }
            }
            $errors[] = $msg;
            return false;
        }
    };

    // =====================================================
    // 1. ADD CURRENCY TO COMPANIES
    // =====================================================
    
    if ($tableExists('companies')) {
        if (!$columnExists('companies', 'currency')) {
            $safeExec("ALTER TABLE companies ADD COLUMN currency VARCHAR(3) DEFAULT 'USD' AFTER billing_email");
        }
    }

    // =====================================================
    // 2. CREATE PLAN_PRICES TABLE (Multi-Currency Pricing)
    // =====================================================
    
    $safeExec("CREATE TABLE IF NOT EXISTS plan_prices (
        id VARCHAR(36) PRIMARY KEY,
        plan_id VARCHAR(50) NOT NULL,
        currency VARCHAR(3) NOT NULL,
        price_monthly DECIMAL(10, 2) DEFAULT 0.00,
        price_yearly DECIMAL(10, 2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_plan_currency (plan_id, currency),
        INDEX idx_plan_id (plan_id),
        INDEX idx_currency (currency)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insert default prices for existing plans (USD, OMR, EUR)
    $defaultPrices = [
        // Free plan
        ['free', 'USD', 0.00, 0.00],
        ['free', 'OMR', 0.00, 0.00],
        ['free', 'EUR', 0.00, 0.00],
        // Pro plan
        ['pro', 'USD', 29.99, 299.99],
        ['pro', 'OMR', 11.50, 115.00],
        ['pro', 'EUR', 27.99, 279.99],
        // Enterprise plan
        ['enterprise', 'USD', 99.99, 999.99],
        ['enterprise', 'OMR', 38.50, 385.00],
        ['enterprise', 'EUR', 92.99, 929.99],
    ];

    foreach ($defaultPrices as $price) {
        $safeExec("INSERT IGNORE INTO plan_prices (id, plan_id, currency, price_monthly, price_yearly) 
                   VALUES (UUID(), '{$price[0]}', '{$price[1]}', {$price[2]}, {$price[3]})");
    }

    // =====================================================
    // 3. CREATE ORDERS TABLE (Unified Physical + Digital)
    // =====================================================
    
    $safeExec("CREATE TABLE IF NOT EXISTS orders (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        order_number VARCHAR(50) UNIQUE NOT NULL,
        order_type ENUM('physical', 'digital') NOT NULL DEFAULT 'digital',
        status VARCHAR(50) DEFAULT 'pending',
        total_amount DECIMAL(10, 2) DEFAULT 0.00,
        currency VARCHAR(3) DEFAULT 'USD',
        payment_status VARCHAR(50) DEFAULT 'unpaid',
        payment_method VARCHAR(50) NULL,
        notes TEXT NULL,
        shipping_address TEXT NULL,
        shipping_method VARCHAR(100) NULL,
        tracking_number VARCHAR(255) NULL,
        print_provider VARCHAR(255) NULL,
        print_provider_order_id VARCHAR(255) NULL,
        created_by VARCHAR(36) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_id (company_id),
        INDEX idx_order_number (order_number),
        INDEX idx_order_type (order_type),
        INDEX idx_status (status),
        INDEX idx_payment_status (payment_status),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // =====================================================
    // 4. CREATE ORDER_ITEMS TABLE
    // =====================================================
    
    $safeExec("CREATE TABLE IF NOT EXISTS order_items (
        id VARCHAR(36) PRIMARY KEY,
        order_id VARCHAR(36) NOT NULL,
        employee_id VARCHAR(36) NULL,
        template_id VARCHAR(100) NULL,
        quantity INT DEFAULT 1,
        unit_price DECIMAL(10, 2) DEFAULT 0.00,
        total_price DECIMAL(10, 2) DEFAULT 0.00,
        front_file_path VARCHAR(500) NULL,
        back_file_path VARCHAR(500) NULL,
        pdf_file_path VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_order_id (order_id),
        INDEX idx_employee_id (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // =====================================================
    // 5. CREATE AUDIT_LOGS TABLE
    // =====================================================
    
    $safeExec("CREATE TABLE IF NOT EXISTS audit_logs (
        id VARCHAR(36) PRIMARY KEY,
        actor_id VARCHAR(36) NULL,
        actor_email VARCHAR(255) NULL,
        actor_role VARCHAR(50) NULL,
        company_id VARCHAR(36) NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100) NOT NULL,
        entity_id VARCHAR(36) NULL,
        before_data JSON NULL,
        after_data JSON NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_actor_id (actor_id),
        INDEX idx_company_id (company_id),
        INDEX idx_action (action),
        INDEX idx_entity_type (entity_type),
        INDEX idx_entity_id (entity_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // =====================================================
    // 6. UPDATE PAYMENT_TRANSACTIONS TABLE
    // =====================================================
    
    if ($tableExists('payment_transactions')) {
        if (!$columnExists('payment_transactions', 'order_id')) {
            $safeExec("ALTER TABLE payment_transactions ADD COLUMN order_id VARCHAR(36) NULL AFTER company_id");
            $safeExec("ALTER TABLE payment_transactions ADD INDEX idx_order_id (order_id)");
        }
        if (!$columnExists('payment_transactions', 'is_manual')) {
            $safeExec("ALTER TABLE payment_transactions ADD COLUMN is_manual BOOLEAN DEFAULT FALSE");
        }
        if (!$columnExists('payment_transactions', 'manual_note')) {
            $safeExec("ALTER TABLE payment_transactions ADD COLUMN manual_note TEXT NULL");
        }
    }

    // =====================================================
    // 7. UPDATE SUBSCRIPTION_PLANS TABLE
    // =====================================================
    
    if ($tableExists('subscription_plans')) {
        if (!$columnExists('subscription_plans', 'sort_order')) {
            $safeExec("ALTER TABLE subscription_plans ADD COLUMN sort_order INT DEFAULT 0");
        }
        // Update sort order for existing plans
        $safeExec("UPDATE subscription_plans SET sort_order = 1 WHERE id = 'free'");
        $safeExec("UPDATE subscription_plans SET sort_order = 2 WHERE id = 'pro'");
        $safeExec("UPDATE subscription_plans SET sort_order = 3 WHERE id = 'enterprise'");
    }

    // =====================================================
    // 8. UPDATE USERS TABLE (ensure role values are correct)
    // =====================================================
    
    if ($tableExists('users')) {
        // First expand the role column to fit 'company_admin' (13 chars)
        $safeExec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'admin'");
        
        // Normalize role values
        $safeExec("UPDATE users SET role = 'company_admin' WHERE role = 'company'");
        $safeExec("UPDATE users SET role = 'company_admin' WHERE role = 'admin' AND company_id IS NOT NULL");
    }

    // =====================================================
    // 9. BACKFILL DEFAULTS FOR EXISTING COMPANIES
    // =====================================================
    
    if ($tableExists('companies') && $columnExists('companies', 'currency')) {
        $safeExec("UPDATE companies SET currency = 'USD' WHERE currency IS NULL OR currency = ''");
    }

    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}
