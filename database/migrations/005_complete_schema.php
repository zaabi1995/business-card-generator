<?php
/**
 * Migration 005: Complete Schema Setup
 * Ensures all tables exist and have all required columns
 * Safe to run multiple times (idempotent)
 */
function migration_005_complete_schema($pdo) {
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
    // 1. CREATE BASE TABLES
    // =====================================================
    
    // Companies table
    $safeExec("CREATE TABLE IF NOT EXISTS companies (
        id VARCHAR(36) PRIMARY KEY,
        parent_company_id VARCHAR(36) NULL,
        company_path VARCHAR(500) NULL,
        company_type VARCHAR(50) DEFAULT 'standalone',
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(100) UNIQUE NOT NULL,
        admin_email VARCHAR(255) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        plan VARCHAR(50) DEFAULT 'free',
        status VARCHAR(50) DEFAULT 'active',
        subscription_id VARCHAR(255) NULL,
        subscription_status VARCHAR(50) NULL,
        subscription_expires_at TIMESTAMP NULL,
        billing_email VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_slug (slug),
        INDEX idx_status (status),
        INDEX idx_plan (plan)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Users table
    $safeExec("CREATE TABLE IF NOT EXISTS users (
        id VARCHAR(36) PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'admin',
        company_id VARCHAR(36) NULL,
        name VARCHAR(255) NOT NULL,
        status VARCHAR(50) DEFAULT 'active',
        last_login_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_role (role),
        INDEX idx_company_id (company_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Departments table
    $safeExec("CREATE TABLE IF NOT EXISTS departments (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        parent_id VARCHAR(36) NULL,
        template_id VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_id (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Employees table
    $safeExec("CREATE TABLE IF NOT EXISTS employees (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        department_id VARCHAR(36) NULL,
        email VARCHAR(255) NOT NULL,
        name_en VARCHAR(255),
        name_ar VARCHAR(255),
        position_en VARCHAR(255),
        position_ar VARCHAR(255),
        phone VARCHAR(50),
        mobile VARCHAR(50),
        company_en VARCHAR(255),
        company_ar VARCHAR(255),
        website VARCHAR(255),
        address TEXT,
        photo VARCHAR(500) NULL,
        status VARCHAR(50) DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_id (company_id),
        INDEX idx_department_id (department_id),
        INDEX idx_email (email),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Templates table
    $safeExec("CREATE TABLE IF NOT EXISTS templates (
        id VARCHAR(100) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        theme_id VARCHAR(36) NULL,
        department_id VARCHAR(36) NULL,
        name VARCHAR(255) NOT NULL,
        side VARCHAR(10) NOT NULL,
        background_image_path VARCHAR(500),
        fields_json JSON,
        is_active BOOLEAN DEFAULT FALSE,
        is_shared BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_side (company_id, side),
        INDEX idx_active (company_id, is_active, side)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Generated cards
    $safeExec("CREATE TABLE IF NOT EXISTS generated_cards (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        employee_id VARCHAR(36) NULL,
        front_template_id VARCHAR(100) NULL,
        back_template_id VARCHAR(100) NULL,
        front_file_path VARCHAR(500),
        back_file_path VARCHAR(500),
        pdf_file_path VARCHAR(500),
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company_date (company_id, generated_at),
        INDEX idx_employee (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Company themes
    $safeExec("CREATE TABLE IF NOT EXISTS company_themes (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        primary_color VARCHAR(7) DEFAULT '#d4af37',
        secondary_color VARCHAR(7) DEFAULT '#0f3460',
        logo_path VARCHAR(500) NULL,
        favicon_path VARCHAR(500) NULL,
        custom_css TEXT NULL,
        header_text VARCHAR(255) NULL,
        footer_text VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_id (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Share links
    $safeExec("CREATE TABLE IF NOT EXISTS share_links (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        employee_id VARCHAR(36) NULL,
        token VARCHAR(100) UNIQUE NOT NULL,
        type VARCHAR(50) DEFAULT 'single',
        expires_at TIMESTAMP NULL,
        view_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_company_id (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Design links
    $safeExec("CREATE TABLE IF NOT EXISTS design_links (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        template_id VARCHAR(100) NOT NULL,
        share_token VARCHAR(64) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NULL,
        expires_at TIMESTAMP NULL,
        access_count INT DEFAULT 0,
        max_access INT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_by VARCHAR(36) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_share_token (share_token),
        INDEX idx_company_id (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Print orders
    $safeExec("CREATE TABLE IF NOT EXISTS print_orders (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        order_number VARCHAR(50) UNIQUE NOT NULL,
        employee_ids JSON NOT NULL,
        template_id VARCHAR(100) NOT NULL,
        quantity INT DEFAULT 1,
        status VARCHAR(50) DEFAULT 'pending',
        print_provider VARCHAR(255) NULL,
        print_provider_order_id VARCHAR(255) NULL,
        total_cost DECIMAL(10, 2) NULL,
        notes TEXT NULL,
        created_by VARCHAR(36) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_id (company_id),
        INDEX idx_order_number (order_number),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Payment transactions
    $safeExec("CREATE TABLE IF NOT EXISTS payment_transactions (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        plan_id VARCHAR(50) NULL,
        amount DECIMAL(10,2) NOT NULL,
        currency VARCHAR(10) DEFAULT 'USD',
        payment_method VARCHAR(50) NULL,
        transaction_id VARCHAR(255) NULL,
        status VARCHAR(50) DEFAULT 'pending',
        payment_gateway VARCHAR(50) NULL,
        gateway_response TEXT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company (company_id),
        INDEX idx_status (status),
        INDEX idx_transaction_id (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Subscription plans
    $safeExec("CREATE TABLE IF NOT EXISTS subscription_plans (
        id VARCHAR(50) PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        price_monthly DECIMAL(10, 2) DEFAULT 0.00,
        price_yearly DECIMAL(10, 2) DEFAULT 0.00,
        max_employees INT DEFAULT 10,
        max_cards_per_month INT DEFAULT 100,
        max_templates INT DEFAULT -1,
        max_storage_mb INT DEFAULT -1,
        features JSON,
        features_json JSON,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // System settings
    $safeExec("CREATE TABLE IF NOT EXISTS system_settings (
        id VARCHAR(36) NULL,
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT,
        setting_type VARCHAR(50) DEFAULT 'string',
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Usage tracking
    $safeExec("CREATE TABLE IF NOT EXISTS usage_tracking (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        metric_type VARCHAR(50) NOT NULL,
        metric_value INT DEFAULT 1,
        period_start DATE NOT NULL,
        period_end DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company_period (company_id, period_start, period_end),
        INDEX idx_type (metric_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Company settings (printer, integrations, etc.)
    $safeExec("CREATE TABLE IF NOT EXISTS company_settings (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        printer_enabled BOOLEAN DEFAULT FALSE,
        printer_name VARCHAR(255) NULL,
        printer_api VARCHAR(500) NULL,
        printer_api_key VARCHAR(255) NULL,
        whatsapp_enabled BOOLEAN DEFAULT FALSE,
        whatsapp_token VARCHAR(500) NULL,
        odoo_enabled BOOLEAN DEFAULT FALSE,
        odoo_url VARCHAR(500) NULL,
        odoo_database VARCHAR(255) NULL,
        odoo_username VARCHAR(255) NULL,
        odoo_password VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_company (company_id),
        INDEX idx_company_id (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Migrations tracking table
    $safeExec("CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration_number INT NOT NULL,
        migration_name VARCHAR(255) NOT NULL,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_migration (migration_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // =====================================================
    // 2. ADD MISSING COLUMNS TO EXISTING TABLES
    // =====================================================
    
    // Companies table - add hierarchy columns
    if ($tableExists('companies')) {
        if (!$columnExists('companies', 'parent_company_id')) {
            $safeExec("ALTER TABLE companies ADD COLUMN parent_company_id VARCHAR(36) NULL AFTER id");
        }
        if (!$columnExists('companies', 'company_path')) {
            $safeExec("ALTER TABLE companies ADD COLUMN company_path VARCHAR(500) NULL AFTER parent_company_id");
        }
        if (!$columnExists('companies', 'company_type')) {
            $safeExec("ALTER TABLE companies ADD COLUMN company_type VARCHAR(50) DEFAULT 'standalone' AFTER company_path");
        }
        
        // Add indexes
        $safeExec("ALTER TABLE companies ADD INDEX idx_parent_company_id (parent_company_id)");
        $safeExec("ALTER TABLE companies ADD INDEX idx_company_path (company_path)");
        $safeExec("ALTER TABLE companies ADD INDEX idx_company_type (company_type)");
    }

    // Employees table - add extra columns
    if ($tableExists('employees')) {
        if (!$columnExists('employees', 'department_id')) {
            $safeExec("ALTER TABLE employees ADD COLUMN department_id VARCHAR(36) NULL AFTER company_id");
        }
        if (!$columnExists('employees', 'photo')) {
            $safeExec("ALTER TABLE employees ADD COLUMN photo VARCHAR(500) NULL");
        }
        if (!$columnExists('employees', 'status')) {
            $safeExec("ALTER TABLE employees ADD COLUMN status VARCHAR(50) DEFAULT 'active'");
        }
        
        $safeExec("ALTER TABLE employees ADD INDEX idx_department_id (department_id)");
        $safeExec("ALTER TABLE employees ADD INDEX idx_status (status)");
    }

    // Templates table - add extra columns
    if ($tableExists('templates')) {
        if (!$columnExists('templates', 'theme_id')) {
            $safeExec("ALTER TABLE templates ADD COLUMN theme_id VARCHAR(36) NULL AFTER company_id");
        }
        if (!$columnExists('templates', 'department_id')) {
            $safeExec("ALTER TABLE templates ADD COLUMN department_id VARCHAR(36) NULL AFTER theme_id");
        }
        if (!$columnExists('templates', 'is_shared')) {
            $safeExec("ALTER TABLE templates ADD COLUMN is_shared BOOLEAN DEFAULT FALSE");
        }
    }

    // System settings - ensure id column exists
    if ($tableExists('system_settings') && !$columnExists('system_settings', 'id')) {
        $safeExec("ALTER TABLE system_settings ADD COLUMN id VARCHAR(36) NULL FIRST");
    }

    // Payment transactions - add missing columns
    if ($tableExists('payment_transactions')) {
        if (!$columnExists('payment_transactions', 'payment_method')) {
            $safeExec("ALTER TABLE payment_transactions ADD COLUMN payment_method VARCHAR(50) NULL");
        }
        if (!$columnExists('payment_transactions', 'transaction_id')) {
            $safeExec("ALTER TABLE payment_transactions ADD COLUMN transaction_id VARCHAR(255) NULL");
            $safeExec("ALTER TABLE payment_transactions ADD INDEX idx_transaction_id (transaction_id)");
        }
        if (!$columnExists('payment_transactions', 'payment_gateway')) {
            $safeExec("ALTER TABLE payment_transactions ADD COLUMN payment_gateway VARCHAR(50) NULL");
        }
        if (!$columnExists('payment_transactions', 'gateway_response')) {
            $safeExec("ALTER TABLE payment_transactions ADD COLUMN gateway_response TEXT NULL");
        }
        if (!$columnExists('payment_transactions', 'plan_id')) {
            $safeExec("ALTER TABLE payment_transactions ADD COLUMN plan_id VARCHAR(50) NULL");
        }
    }

    // Subscription plans - add missing columns
    if ($tableExists('subscription_plans')) {
        if (!$columnExists('subscription_plans', 'max_templates')) {
            $safeExec("ALTER TABLE subscription_plans ADD COLUMN max_templates INT DEFAULT -1");
        }
        if (!$columnExists('subscription_plans', 'max_storage_mb')) {
            $safeExec("ALTER TABLE subscription_plans ADD COLUMN max_storage_mb INT DEFAULT -1");
        }
        if (!$columnExists('subscription_plans', 'max_employees')) {
            $safeExec("ALTER TABLE subscription_plans ADD COLUMN max_employees INT DEFAULT 10");
        }
        if (!$columnExists('subscription_plans', 'max_cards_per_month')) {
            $safeExec("ALTER TABLE subscription_plans ADD COLUMN max_cards_per_month INT DEFAULT 100");
        }
    }

    // System settings - add missing columns
    if ($tableExists('system_settings')) {
        if (!$columnExists('system_settings', 'setting_type')) {
            $safeExec("ALTER TABLE system_settings ADD COLUMN setting_type VARCHAR(50) DEFAULT 'string'");
        }
        if (!$columnExists('system_settings', 'description')) {
            $safeExec("ALTER TABLE system_settings ADD COLUMN description TEXT NULL");
        }
        if (!$columnExists('system_settings', 'created_at')) {
            $safeExec("ALTER TABLE system_settings ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
    }

    // =====================================================
    // 3. INSERT DEFAULT DATA
    // =====================================================
    
    // Default subscription plans
    $safeExec("INSERT IGNORE INTO subscription_plans (id, name, description, price_monthly, price_yearly, max_employees, max_templates, max_storage_mb) VALUES
        ('free', 'Free', 'Perfect for individuals', 0.00, 0.00, 5, 2, 100),
        ('pro', 'Pro', 'For growing teams', 29.99, 299.99, 50, 10, 1000),
        ('enterprise', 'Enterprise', 'For large organizations', 99.99, 999.99, -1, -1, -1)
    ");

    // Default system settings
    $safeExec("INSERT IGNORE INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
        ('installation_complete', '1', 'boolean', 'Installation completed'),
        ('site_name', 'Cardify', 'string', 'Site name'),
        ('maintenance_mode', '0', 'boolean', 'Maintenance mode')
    ");

    // =====================================================
    // 4. UPDATE EXISTING DATA
    // =====================================================
    
    // Set company_path for companies without one
    try {
        $stmt = $pdo->query("SELECT id, name FROM companies WHERE company_path IS NULL OR company_path = ''");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->prepare("UPDATE companies SET company_path = ? WHERE id = ?")
                ->execute([$row['name'], $row['id']]);
        }
    } catch (PDOException $e) {
        // Ignore if table doesn't exist
    }

    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}
