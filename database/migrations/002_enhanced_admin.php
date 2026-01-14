<?php
/**
 * Migration 002: Enhanced Admin Panel Features
 * Adds users, themes, departments, shareable links, and print orders
 */
function migration_002_enhanced_admin($pdo) {
    $errors = [];
    
    try {
        // Users table
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id VARCHAR(36) PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'company',
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
        
        // Company themes
        $pdo->exec("CREATE TABLE IF NOT EXISTS company_themes (
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
            UNIQUE KEY unique_company_theme (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Departments
        $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
            id VARCHAR(36) PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            template_id VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_company_id (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Add department_id to employees if not exists
        try {
            $pdo->exec("ALTER TABLE employees ADD COLUMN department_id VARCHAR(36) NULL AFTER company_id");
            $pdo->exec("ALTER TABLE employees ADD INDEX idx_department_id (department_id)");
        } catch (PDOException $e) {
            // Column might already exist
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding department_id to employees: " . $e->getMessage();
            }
        }
        
        // Design links
        $pdo->exec("CREATE TABLE IF NOT EXISTS design_links (
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
            INDEX idx_company_id (company_id),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Print orders
        $pdo->exec("CREATE TABLE IF NOT EXISTS print_orders (
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
        
        // Add theme_id and department_id to templates if not exists
        try {
            $pdo->exec("ALTER TABLE templates ADD COLUMN theme_id VARCHAR(36) NULL AFTER company_id");
            $pdo->exec("ALTER TABLE templates ADD COLUMN department_id VARCHAR(36) NULL AFTER theme_id");
            $pdo->exec("ALTER TABLE templates ADD COLUMN is_shared BOOLEAN DEFAULT FALSE");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                $errors[] = "Error adding columns to templates: " . $e->getMessage();
            }
        }
        
        // Create default super admin (password: admin123 - CHANGE THIS!)
        $adminPassword = password_hash('admin123', PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash, role, name, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                bin2hex(random_bytes(18)),
                'admin@bhd.om',
                $adminPassword,
                'super_admin',
                'Super Admin',
                'active'
            ]);
        } catch (PDOException $e) {
            // User might already exist
            if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                $errors[] = "Error creating super admin: " . $e->getMessage();
            }
        }
        
    } catch (PDOException $e) {
        $errors[] = "Migration error: " . $e->getMessage();
    }
    
    return [
        'success' => empty($errors),
        'errors' => $errors
    ];
}
