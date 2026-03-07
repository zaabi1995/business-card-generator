<?php
/**
 * Migration 015: Card Requests Table
 * Employee self-service portal for business card requests
 */

function migration_015_card_requests($pdo) {
    $errors = [];
    
    // Helper function to safely execute SQL
    $safeExec = function($sql, $description) use ($pdo, &$errors) {
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate') !== false) {
                return true;
            }
            $errors[] = "{$description}: " . $e->getMessage();
            return false;
        }
    };
    
    // Helper to check if column exists
    $columnExists = function($table, $column) use ($pdo) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Helper to check if table exists
    $tableExists = function($table) use ($pdo) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Create card_requests table
    $safeExec("CREATE TABLE IF NOT EXISTS card_requests (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        email VARCHAR(255) NOT NULL,
        name_en VARCHAR(255),
        name_ar VARCHAR(255),
        position_en VARCHAR(255),
        position_ar VARCHAR(255),
        phone VARCHAR(50),
        phone_ar VARCHAR(50),
        mobile VARCHAR(50),
        mobile_ar VARCHAR(50),
        website VARCHAR(255),
        website_ar VARCHAR(255),
        address_en TEXT,
        address_ar TEXT,
        company_en VARCHAR(255),
        company_ar VARCHAR(255),
        department_id VARCHAR(36) NULL,
        photo VARCHAR(500) NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        admin_notes TEXT NULL,
        employee_id VARCHAR(36) NULL COMMENT 'Set when request is approved and employee is created',
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL,
        reviewed_by VARCHAR(36) NULL,
        INDEX idx_company_status (company_id, status),
        INDEX idx_email (email),
        INDEX idx_status (status),
        INDEX idx_submitted_at (submitted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "Create card_requests table");
    
    // Add company domain field to companies table if not exists
    if ($tableExists('companies') && !$columnExists('companies', 'email_domain')) {
        $safeExec(
            "ALTER TABLE companies ADD COLUMN email_domain VARCHAR(255) NULL AFTER admin_email",
            "Add email_domain column to companies"
        );
    }
    
    // Add portal_enabled field to companies table
    if ($tableExists('companies') && !$columnExists('companies', 'portal_enabled')) {
        $safeExec(
            "ALTER TABLE companies ADD COLUMN portal_enabled TINYINT(1) DEFAULT 1 AFTER status",
            "Add portal_enabled column to companies"
        );
    }
    
    // Add notification_email field to companies table (for admin notifications)
    if ($tableExists('companies') && !$columnExists('companies', 'notification_email')) {
        $safeExec(
            "ALTER TABLE companies ADD COLUMN notification_email VARCHAR(255) NULL AFTER admin_email",
            "Add notification_email column to companies"
        );
    }
    
    // Return in expected format
    if (empty($errors)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'errors' => $errors];
    }
}

// Run migration if called directly
if (php_sapi_name() === 'cli' || (isset($_GET['run']) && $_GET['run'] === '1')) {
    require_once __DIR__ . '/../../config.php';
    
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    if (!$pdo) {
        die("Database connection failed\n");
    }
    
    echo "Running migration 015_card_requests...\n\n";
    $result = migration_015_card_requests($pdo);
    
    if ($result['success']) {
        echo "Migration completed successfully.\n";
    } else {
        echo "Migration failed:\n";
        foreach ($result['errors'] ?? [] as $error) {
            echo "  - $error\n";
        }
    }
}
