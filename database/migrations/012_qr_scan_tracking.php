<?php
/**
 * Migration 012: QR Scan Tracking
 * Creates table to track QR code scans for analytics
 * Safe to run multiple times (idempotent)
 */
function migration_012_qr_scan_tracking($pdo) {
    $errors = [];
    
    // Helper function to check if table exists
    $tableExists = function($table) use ($pdo) {
        try {
            $result = $pdo->query("SHOW TABLES LIKE '$table'");
            return $result && $result->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Helper function to check if column exists
    $columnExists = function($table, $column) use ($pdo) {
        try {
            $result = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $result && $result->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    };
    
    // Helper function to safely execute SQL
    $safeExec = function($sql, $description = '') use ($pdo, &$errors) {
        try {
            $pdo->exec($sql);
            return true;
        } catch (PDOException $e) {
            $errors[] = ($description ? "$description: " : '') . $e->getMessage();
            return false;
        }
    };

    // =====================================================
    // 1. Create qr_scans table
    // =====================================================
    if (!$tableExists('qr_scans')) {
        $safeExec("
            CREATE TABLE qr_scans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id VARCHAR(36) NOT NULL,
                company_id VARCHAR(36) NOT NULL,
                scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                -- Visitor identification
                visitor_id VARCHAR(64) NULL COMMENT 'Cookie-based unique visitor ID',
                ip_address VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 address',
                
                -- Device information
                user_agent TEXT NULL,
                device_type ENUM('desktop', 'mobile', 'tablet', 'unknown') DEFAULT 'unknown',
                browser VARCHAR(100) NULL,
                browser_version VARCHAR(50) NULL,
                os VARCHAR(100) NULL,
                os_version VARCHAR(50) NULL,
                
                -- Location (from IP geolocation)
                country_code VARCHAR(2) NULL,
                country_name VARCHAR(100) NULL,
                region VARCHAR(100) NULL,
                city VARCHAR(100) NULL,
                latitude DECIMAL(10, 8) NULL,
                longitude DECIMAL(11, 8) NULL,
                timezone VARCHAR(50) NULL,
                
                -- Referrer and context
                referrer TEXT NULL,
                landing_url TEXT NULL,
                
                -- Indexes
                INDEX idx_employee (employee_id),
                INDEX idx_company (company_id),
                INDEX idx_scanned_at (scanned_at),
                INDEX idx_visitor (visitor_id),
                INDEX idx_country (country_code),
                INDEX idx_device (device_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ", "Create qr_scans table");
    }

    // =====================================================
    // 2. Create qr_scan_daily_stats table for aggregated stats
    // =====================================================
    if (!$tableExists('qr_scan_daily_stats')) {
        $safeExec("
            CREATE TABLE qr_scan_daily_stats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id VARCHAR(36) NOT NULL,
                company_id VARCHAR(36) NOT NULL,
                scan_date DATE NOT NULL,
                
                -- Aggregated counts
                total_scans INT DEFAULT 0,
                unique_visitors INT DEFAULT 0,
                mobile_scans INT DEFAULT 0,
                desktop_scans INT DEFAULT 0,
                tablet_scans INT DEFAULT 0,
                
                -- Top countries (JSON array)
                top_countries JSON NULL,
                
                -- Timestamps
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                -- Unique constraint
                UNIQUE KEY unique_employee_date (employee_id, scan_date),
                INDEX idx_company_date (company_id, scan_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ", "Create qr_scan_daily_stats table");
    }

    // =====================================================
    // 3. Add scan_count to employees table for quick access
    // =====================================================
    if (!$columnExists('employees', 'total_scans')) {
        $safeExec(
            "ALTER TABLE employees ADD COLUMN total_scans INT DEFAULT 0 AFTER updated_at",
            "Add total_scans column to employees"
        );
    }
    
    if (!$columnExists('employees', 'last_scanned_at')) {
        $safeExec(
            "ALTER TABLE employees ADD COLUMN last_scanned_at TIMESTAMP NULL AFTER total_scans",
            "Add last_scanned_at column to employees"
        );
    }

    // Return in expected format
    if (empty($errors)) {
        return ['success' => true];
    } else {
        return ['success' => false, 'errors' => $errors];
    }
}
