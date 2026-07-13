<?php
// 131_scan_module.php: tables for the Cardify Scan module (app rolodex + claim loop)
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();

    $pdo->exec("CREATE TABLE IF NOT EXISTS shadow_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone_primary VARCHAR(32) NULL,
        email_primary VARCHAR(190) NULL,
        best_parsed LONGTEXT NULL,
        claim_token CHAR(43) NOT NULL,
        claimed_at DATETIME NULL,
        claimed_company_id VARCHAR(36) NULL,
        invite_sent_at DATETIME NULL,
        invited_by_employee_id VARCHAR(36) NULL,
        opted_out TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_claim_token (claim_token),
        UNIQUE KEY uniq_phone (phone_primary),
        UNIQUE KEY uniq_email (email_primary)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS scans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        company_id VARCHAR(36) NOT NULL,
        device_uuid VARCHAR(64) NULL,
        image_path VARCHAR(255) NULL,
        raw_text TEXT NULL,
        parsed LONGTEXT NULL,
        parse_tier TINYINT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'parsed',
        tags VARCHAR(500) NULL,
        met_at DATE NULL,
        met_where VARCHAR(255) NULL,
        shadow_profile_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_emp_device (employee_id, device_uuid),
        KEY idx_employee (employee_id),
        KEY idx_shadow (shadow_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        label VARCHAR(100) NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        last_used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_token_hash (token_hash),
        KEY idx_employee (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "Migration 131 OK\n";
} catch (Throwable $e) {
    echo "Migration 131 failed: " . $e->getMessage() . "\n";
    exit(1);
}
