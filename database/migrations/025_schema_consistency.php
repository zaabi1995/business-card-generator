<?php
/**
 * Migration 026: Schema Consistency Check
 * 
 * This migration ensures all tables have consistent column types and structures.
 * Fixes issues caused by conflicting migrations running in different orders.
 * 
 * Safe to run multiple times (idempotent)
 */

function migration_025_schema_consistency($pdo) {
    $results = ['success' => true, 'messages' => []];
    
    // Helper: Check if table exists
    $tableExists = function($table) use ($pdo) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Helper: Check if column exists
    $columnExists = function($table, $column) use ($pdo) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Helper: Get column info
    $getColumnInfo = function($table, $column) use ($pdo) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    };
    
    // Helper: Safe execute
    $safeExec = function($sql, $description) use ($pdo, &$results) {
        try {
            $pdo->exec($sql);
            $results['messages'][] = "SUCCESS: $description";
            return true;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            // Ignore expected errors
            if (strpos($msg, 'Duplicate') !== false || 
                strpos($msg, 'already exists') !== false ||
                strpos($msg, '1060') !== false ||
                strpos($msg, '1061') !== false) {
                return true;
            }
            $results['messages'][] = "WARNING: $description - $msg";
            return false;
        }
    };

    // =========================================================================
    // 1. USERS TABLE
    // =========================================================================
    if ($tableExists('users')) {
        $results['messages'][] = "--- Checking users table ---";
        
        // Ensure id is VARCHAR(36) for UUID
        $idInfo = $getColumnInfo('users', 'id');
        if ($idInfo && strpos(strtoupper($idInfo['Type']), 'INT') !== false) {
            // ID is INT, needs migration - but this is complex, just warn
            $results['messages'][] = "WARNING: users.id is INT - should be VARCHAR(36) for UUID. Manual migration may be needed.";
        }
        
        // Ensure role supports all types
        $roleInfo = $getColumnInfo('users', 'role');
        if ($roleInfo) {
            $roleType = $roleInfo['Type'];
            // Check if all roles are supported
            $requiredRoles = ['super_admin', 'admin', 'company', 'employee', 'user', 'print_shop'];
            $missingRoles = [];
            foreach ($requiredRoles as $role) {
                if (strpos($roleType, $role) === false) {
                    $missingRoles[] = $role;
                }
            }
            if (!empty($missingRoles)) {
                $safeExec(
                    "ALTER TABLE users MODIFY COLUMN role ENUM('super_admin', 'admin', 'company', 'employee', 'user', 'print_shop') DEFAULT 'user'",
                    "Updated users.role to include all required roles"
                );
            }
        }
        
        // Ensure name column exists
        if (!$columnExists('users', 'name')) {
            $safeExec("ALTER TABLE users ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '' AFTER password_hash", "Added users.name column");
        }
        
        // Ensure status column exists
        if (!$columnExists('users', 'status')) {
            $safeExec("ALTER TABLE users ADD COLUMN status VARCHAR(50) DEFAULT 'active'", "Added users.status column");
        }
        
        // Ensure last_login_at column exists
        if (!$columnExists('users', 'last_login_at')) {
            $safeExec("ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL", "Added users.last_login_at column");
        }
    }
    
    // =========================================================================
    // 2. COMPANIES TABLE
    // =========================================================================
    if ($tableExists('companies')) {
        $results['messages'][] = "--- Checking companies table ---";
        
        $companyColumns = [
            'currency' => "VARCHAR(10) DEFAULT 'OMR'",
            'country' => "VARCHAR(100) DEFAULT 'OM'",
            'domain' => "VARCHAR(255) NULL",
            'email_domain' => "VARCHAR(255) NULL",
            'portal_enabled' => "TINYINT(1) DEFAULT 1",
            'portal_show_preview' => "TINYINT(1) DEFAULT 1",
            'notification_email' => "VARCHAR(255) NULL",
            'parent_company_id' => "VARCHAR(36) NULL",
            'company_path' => "VARCHAR(500) NULL",
            'company_type' => "VARCHAR(50) DEFAULT 'standalone'"
        ];
        
        foreach ($companyColumns as $col => $def) {
            if (!$columnExists('companies', $col)) {
                $safeExec("ALTER TABLE companies ADD COLUMN $col $def", "Added companies.$col column");
            }
        }
    }
    
    // =========================================================================
    // 3. EMPLOYEES TABLE
    // =========================================================================
    if ($tableExists('employees')) {
        $results['messages'][] = "--- Checking employees table ---";
        
        $employeeColumns = [
            'department_id' => "VARCHAR(36) NULL",
            'status' => "VARCHAR(50) DEFAULT 'active'",
            'photo' => "VARCHAR(500) NULL",
            'password_hash' => "VARCHAR(255) NULL",
            'email_ar' => "VARCHAR(255) NULL",
            'phone_ar' => "VARCHAR(50) NULL",
            'mobile_ar' => "VARCHAR(50) NULL",
            'website_ar' => "VARCHAR(255) NULL",
            'address_ar' => "TEXT NULL",
            'fax' => "VARCHAR(50) NULL",
            'fax_ar' => "VARCHAR(50) NULL",
            'po_box' => "VARCHAR(50) NULL",
            'po_box_ar' => "VARCHAR(50) NULL",
            'linkedin' => "VARCHAR(255) NULL",
            'twitter' => "VARCHAR(255) NULL",
            'employee_id' => "VARCHAR(100) NULL COMMENT 'Employee ID/Number'",
            'card_template_id' => "VARCHAR(100) NULL"
        ];
        
        foreach ($employeeColumns as $col => $def) {
            if (!$columnExists('employees', $col)) {
                $safeExec("ALTER TABLE employees ADD COLUMN $col $def", "Added employees.$col column");
            }
        }
    }
    
    // =========================================================================
    // 4. PRINT_SHOPS TABLE
    // =========================================================================
    if ($tableExists('print_shops')) {
        $results['messages'][] = "--- Checking print_shops table ---";
        
        // Critical: Ensure user_id is VARCHAR(36) for UUID
        $userIdInfo = $getColumnInfo('print_shops', 'user_id');
        if ($userIdInfo) {
            $currentType = strtoupper($userIdInfo['Type']);
            if (strpos($currentType, 'INT') !== false && strpos($currentType, 'VARCHAR') === false) {
                $safeExec(
                    "ALTER TABLE print_shops MODIFY COLUMN user_id VARCHAR(36) NULL",
                    "Changed print_shops.user_id from INT to VARCHAR(36)"
                );
            }
        } else {
            $safeExec("ALTER TABLE print_shops ADD COLUMN user_id VARCHAR(36) NULL", "Added print_shops.user_id column");
        }
        
        // Other print_shops columns
        $printShopColumns = [
            'tagline' => "VARCHAR(255) NULL",
            'facebook' => "VARCHAR(255) NULL",
            'instagram' => "VARCHAR(255) NULL",
            'twitter' => "VARCHAR(255) NULL",
            'whatsapp' => "VARCHAR(50) NULL",
            // Document settings
            'require_quotation' => "BOOLEAN DEFAULT FALSE",
            'require_po' => "BOOLEAN DEFAULT FALSE",
            'auto_invoice' => "BOOLEAN DEFAULT TRUE",
            'quotation_validity_days' => "INT DEFAULT 30",
            'payment_terms_days' => "INT DEFAULT 30",
            'tax_rate' => "DECIMAL(5,2) DEFAULT 0.00",
            'tax_number' => "VARCHAR(100) NULL",
            // Document number prefixes
            'quotation_prefix' => "VARCHAR(20) DEFAULT 'QT-'",
            'invoice_prefix' => "VARCHAR(20) DEFAULT 'INV-'",
            'delivery_note_prefix' => "VARCHAR(20) DEFAULT 'DN-'",
            // ERP Integration
            'erp_enabled' => "BOOLEAN DEFAULT FALSE",
            'erp_system' => "VARCHAR(50) NULL COMMENT 'odoo, sap, zoho, quickbooks, etc.'",
            'erp_url' => "VARCHAR(500) NULL",
            'erp_database' => "VARCHAR(100) NULL",
            'erp_username' => "VARCHAR(255) NULL",
            'erp_api_key' => "VARCHAR(500) NULL",
            'erp_company_id' => "VARCHAR(100) NULL",
            'erp_settings' => "JSON NULL COMMENT 'Additional ERP-specific settings'",
            'erp_last_sync' => "TIMESTAMP NULL",
            'erp_sync_enabled' => "BOOLEAN DEFAULT FALSE COMMENT 'Auto-sync orders to ERP'"
        ];
        
        foreach ($printShopColumns as $col => $def) {
            if (!$columnExists('print_shops', $col)) {
                $safeExec("ALTER TABLE print_shops ADD COLUMN $col $def", "Added print_shops.$col column");
            }
        }
    }
    
    // =========================================================================
    // 5. PRINT_ORDERS TABLE
    // =========================================================================
    if ($tableExists('print_orders')) {
        $results['messages'][] = "--- Checking print_orders table ---";
        
        // Check ID type - should be INT AUTO_INCREMENT
        $idInfo = $getColumnInfo('print_orders', 'id');
        $idIsVarchar = ($idInfo && strpos(strtoupper($idInfo['Type']), 'VARCHAR') !== false);
        
        // Ensure employee_id is VARCHAR(36)
        $empIdInfo = $getColumnInfo('print_orders', 'employee_id');
        if ($empIdInfo) {
            $currentType = strtoupper($empIdInfo['Type']);
            if (strpos($currentType, 'INT') !== false && strpos($currentType, 'VARCHAR') === false) {
                $safeExec(
                    "ALTER TABLE print_orders MODIFY COLUMN employee_id VARCHAR(36) NULL",
                    "Changed print_orders.employee_id from INT to VARCHAR(36)"
                );
            }
        } else {
            $safeExec("ALTER TABLE print_orders ADD COLUMN employee_id VARCHAR(36) NULL", "Added print_orders.employee_id column");
        }
        
        // Ensure user_id is VARCHAR(36)
        $userIdInfo = $getColumnInfo('print_orders', 'user_id');
        if ($userIdInfo) {
            $currentType = strtoupper($userIdInfo['Type']);
            if (strpos($currentType, 'INT') !== false && strpos($currentType, 'VARCHAR') === false) {
                $safeExec(
                    "ALTER TABLE print_orders MODIFY COLUMN user_id VARCHAR(36) NULL",
                    "Changed print_orders.user_id from INT to VARCHAR(36)"
                );
            }
        } else {
            $safeExec("ALTER TABLE print_orders ADD COLUMN user_id VARCHAR(36) NULL", "Added print_orders.user_id column");
        }
        
        // Ensure company_id is VARCHAR(36)
        $compIdInfo = $getColumnInfo('print_orders', 'company_id');
        if ($compIdInfo) {
            $currentType = strtoupper($compIdInfo['Type']);
            if (strpos($currentType, 'INT') !== false && strpos($currentType, 'VARCHAR') === false) {
                $safeExec(
                    "ALTER TABLE print_orders MODIFY COLUMN company_id VARCHAR(36) NULL",
                    "Changed print_orders.company_id from INT to VARCHAR(36)"
                );
            }
        }
        
        // Essential print_orders columns
        $printOrderColumns = [
            'order_number' => "VARCHAR(50) NULL",
            'print_shop_id' => "INT NULL",
            'card_template_id' => "VARCHAR(100) NULL",
            'quantity' => "INT NOT NULL DEFAULT 100",
            'paper_type' => "VARCHAR(50) DEFAULT 'matte'",
            'finish' => "VARCHAR(50) DEFAULT 'standard'",
            'card_size' => "VARCHAR(50) DEFAULT 'standard'",
            'card_front_url' => "VARCHAR(500) NULL",
            'card_back_url' => "VARCHAR(500) NULL",
            'subtotal' => "DECIMAL(10,3) DEFAULT 0.000",
            'setup_fee' => "DECIMAL(10,3) DEFAULT 0.000",
            'shipping_fee' => "DECIMAL(10,3) DEFAULT 0.000",
            'express_fee' => "DECIMAL(10,3) DEFAULT 0.000",
            'total' => "DECIMAL(10,3) DEFAULT 0.000",
            'currency' => "VARCHAR(10) DEFAULT 'OMR'",
            'shipping_name' => "VARCHAR(255) NULL",
            'shipping_address' => "TEXT NULL",
            'shipping_city' => "VARCHAR(100) NULL",
            'shipping_state' => "VARCHAR(100) NULL",
            'shipping_country' => "VARCHAR(100) NULL",
            'shipping_postal' => "VARCHAR(20) NULL",
            'shipping_phone' => "VARCHAR(50) NULL",
            'notes' => "TEXT NULL",
            'status' => "VARCHAR(50) DEFAULT 'pending'",
            'payment_status' => "VARCHAR(50) DEFAULT 'pending'",
            'express_delivery' => "BOOLEAN DEFAULT FALSE",
            'external_order_id' => "VARCHAR(100) NULL",
            'tracking_number' => "VARCHAR(100) NULL",
            'print_provider' => "VARCHAR(255) NULL",
            'api_response' => "TEXT NULL",
            'api_error' => "TEXT NULL",
            // Quotation fields
            'quotation_requested' => "BOOLEAN DEFAULT FALSE",
            'quotation_number' => "VARCHAR(100) NULL",
            'quotation_file_path' => "VARCHAR(500) NULL",
            'quotation_amount' => "DECIMAL(10,3) NULL",
            'quotation_issued_at' => "TIMESTAMP NULL",
            'quotation_valid_until' => "DATE NULL",
            'quotation_accepted' => "BOOLEAN DEFAULT FALSE",
            'quotation_accepted_at' => "TIMESTAMP NULL",
            'quotation_external_id' => "VARCHAR(100) NULL COMMENT 'External ERP quotation ID'",
            
            // Purchase Order (PO) fields
            'po_required' => "BOOLEAN DEFAULT FALSE",
            'po_number' => "VARCHAR(100) NULL",
            'po_file_path' => "VARCHAR(500) NULL",
            'po_received_at' => "TIMESTAMP NULL",
            'po_approved' => "BOOLEAN DEFAULT FALSE",
            'po_approved_by' => "VARCHAR(36) NULL",
            'po_approved_at' => "TIMESTAMP NULL",
            'po_external_id' => "VARCHAR(100) NULL COMMENT 'External ERP PO ID'",
            
            // Invoice fields
            'invoice_number' => "VARCHAR(100) NULL",
            'invoice_file_path' => "VARCHAR(500) NULL",
            'invoice_amount' => "DECIMAL(10,3) NULL",
            'invoice_issued_at' => "TIMESTAMP NULL",
            'invoice_due_date' => "DATE NULL",
            'invoice_paid' => "BOOLEAN DEFAULT FALSE",
            'invoice_paid_at' => "TIMESTAMP NULL",
            'invoice_external_id' => "VARCHAR(100) NULL COMMENT 'External ERP invoice ID'",
            
            // Delivery Note fields
            'delivery_note_number' => "VARCHAR(100) NULL",
            'delivery_note_file_path' => "VARCHAR(500) NULL",
            'delivery_note_issued_at' => "TIMESTAMP NULL",
            'delivery_note_external_id' => "VARCHAR(100) NULL COMMENT 'External ERP delivery note ID'",
            
            // ERP Integration fields
            'erp_system' => "VARCHAR(50) NULL COMMENT 'odoo, sap, etc.'",
            'erp_order_id' => "VARCHAR(100) NULL",
            'erp_customer_id' => "VARCHAR(100) NULL",
            'erp_sync_status' => "VARCHAR(50) DEFAULT 'none' COMMENT 'none, pending, synced, error'",
            'erp_last_sync' => "TIMESTAMP NULL",
            'erp_sync_error' => "TEXT NULL",
            // Timestamps
            'created_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP",
            'shipped_at' => "TIMESTAMP NULL",
            'delivered_at' => "TIMESTAMP NULL"
        ];
        
        foreach ($printOrderColumns as $col => $def) {
            if (!$columnExists('print_orders', $col)) {
                $safeExec("ALTER TABLE print_orders ADD COLUMN $col $def", "Added print_orders.$col column");
            }
        }
        
        // Add index on print_shop_id if not exists
        try {
            $pdo->exec("ALTER TABLE print_orders ADD INDEX idx_print_shop_id (print_shop_id)");
        } catch (PDOException $e) {
            // Index may already exist
        }
    }
    
    // =========================================================================
    // 6. GENERATED_CARDS TABLE
    // =========================================================================
    if ($tableExists('generated_cards')) {
        $results['messages'][] = "--- Checking generated_cards table ---";
        
        // Ensure employee_id is VARCHAR(36)
        $empIdInfo = $getColumnInfo('generated_cards', 'employee_id');
        if ($empIdInfo) {
            $currentType = strtoupper($empIdInfo['Type']);
            if (strpos($currentType, 'INT') !== false && strpos($currentType, 'VARCHAR') === false) {
                $safeExec(
                    "ALTER TABLE generated_cards MODIFY COLUMN employee_id VARCHAR(36) NULL",
                    "Changed generated_cards.employee_id from INT to VARCHAR(36)"
                );
            }
        }
        
        // Ensure company_id is VARCHAR(36)
        $compIdInfo = $getColumnInfo('generated_cards', 'company_id');
        if ($compIdInfo) {
            $currentType = strtoupper($compIdInfo['Type']);
            if (strpos($currentType, 'INT') !== false && strpos($currentType, 'VARCHAR') === false) {
                $safeExec(
                    "ALTER TABLE generated_cards MODIFY COLUMN company_id VARCHAR(36) NULL",
                    "Changed generated_cards.company_id from INT to VARCHAR(36)"
                );
            }
        }
    }
    
    // =========================================================================
    // 7. TEMPLATES TABLE
    // =========================================================================
    if ($tableExists('templates')) {
        $results['messages'][] = "--- Checking templates table ---";
        
        $templateColumns = [
            'theme_id' => "VARCHAR(36) NULL",
            'department_id' => "VARCHAR(36) NULL",
            'is_shared' => "BOOLEAN DEFAULT FALSE",
            'original_pdf_path' => "VARCHAR(500) NULL",
            'original_pdf_page' => "INT DEFAULT 1",
            'paired_template_id' => "VARCHAR(100) NULL"
        ];
        
        foreach ($templateColumns as $col => $def) {
            if (!$columnExists('templates', $col)) {
                $safeExec("ALTER TABLE templates ADD COLUMN $col $def", "Added templates.$col column");
            }
        }
    }
    
    // =========================================================================
    // 8. DEPARTMENTS TABLE
    // =========================================================================
    if ($tableExists('departments')) {
        $results['messages'][] = "--- Checking departments table ---";
        
        $departmentColumns = [
            'template_id' => "VARCHAR(100) NULL",
            'template_front_id' => "VARCHAR(100) NULL",
            'template_back_id' => "VARCHAR(100) NULL",
            'portal_slug' => "VARCHAR(100) NULL",
            'portal_enabled' => "TINYINT(1) DEFAULT 0",
            'access_code' => "VARCHAR(20) NULL"
        ];
        
        foreach ($departmentColumns as $col => $def) {
            if (!$columnExists('departments', $col)) {
                $safeExec("ALTER TABLE departments ADD COLUMN $col $def", "Added departments.$col column");
            }
        }
    }
    
    // =========================================================================
    // 9. CARD_REQUESTS TABLE
    // =========================================================================
    if ($tableExists('card_requests')) {
        $results['messages'][] = "--- Checking card_requests table ---";
        
        $requestColumns = [
            'request_type' => "VARCHAR(50) DEFAULT 'new'",
            'preview_front_path' => "VARCHAR(500) NULL",
            'preview_back_path' => "VARCHAR(500) NULL"
        ];
        
        foreach ($requestColumns as $col => $def) {
            if (!$columnExists('card_requests', $col)) {
                $safeExec("ALTER TABLE card_requests ADD COLUMN $col $def", "Added card_requests.$col column");
            }
        }
    }
    
    // =========================================================================
    // 10. EMAIL_LOGS TABLE
    // =========================================================================
    if (!$tableExists('email_logs')) {
        $safeExec("CREATE TABLE IF NOT EXISTS email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            to_email VARCHAR(255) NOT NULL,
            from_email VARCHAR(255) NULL,
            subject VARCHAR(500) NOT NULL,
            body TEXT NULL,
            template_name VARCHAR(100) NULL,
            status ENUM('sent', 'failed', 'queued') DEFAULT 'sent',
            error_message TEXT NULL,
            metadata JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_to_email (to_email),
            INDEX idx_status (status),
            INDEX idx_template (template_name),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Created email_logs table");
    }
    
    // =========================================================================
    // 11. PASSWORD_RESET_TOKENS TABLE
    // =========================================================================
    if (!$tableExists('password_reset_tokens')) {
        $safeExec("CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(255) NOT NULL UNIQUE,
            user_type VARCHAR(50) NOT NULL,
            user_id VARCHAR(36) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            INDEX idx_email (email),
            INDEX idx_token (token),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Created password_reset_tokens table");
    }
    
    // =========================================================================
    // 12. QR_SCANS TABLE
    // =========================================================================
    if (!$tableExists('qr_scans')) {
        $safeExec("CREATE TABLE IF NOT EXISTS qr_scans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            employee_id VARCHAR(36) NULL,
            card_id VARCHAR(36) NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            referer VARCHAR(500) NULL,
            country VARCHAR(100) NULL,
            city VARCHAR(100) NULL,
            device_type VARCHAR(50) NULL,
            scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company_id (company_id),
            INDEX idx_employee_id (employee_id),
            INDEX idx_scanned_at (scanned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Created qr_scans table");
    }
    
    // =========================================================================
    // 13. AUDIT_LOGS TABLE
    // =========================================================================
    if (!$tableExists('audit_logs')) {
        $safeExec("CREATE TABLE IF NOT EXISTS audit_logs (
            id VARCHAR(36) PRIMARY KEY,
            company_id VARCHAR(36) NULL,
            user_id VARCHAR(36) NULL,
            user_email VARCHAR(255) NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(100) NULL,
            entity_id VARCHAR(36) NULL,
            old_values JSON NULL,
            new_values JSON NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company_id (company_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "Created audit_logs table");
    }
    
    $results['messages'][] = "--- Schema consistency check complete ---";
    
    return $results;
}
