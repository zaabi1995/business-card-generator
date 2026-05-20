<?php
// database/migrations/111_print_tracking.php
// Print PO tracking: a company has one or more POs (ordered quantities)
// and a log of print runs that draw down the pool. Remaining = SUM(pos) - SUM(runs).
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS company_print_pos (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        po_number VARCHAR(100) NULL,
        quantity INT NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL,
        attachment_path VARCHAR(255) NULL,
        created_by VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 111: company_print_pos created\n";

    $db->exec("CREATE TABLE IF NOT EXISTS company_print_runs (
        id VARCHAR(36) PRIMARY KEY,
        company_id VARCHAR(36) NOT NULL,
        employee_id VARCHAR(36) NULL,
        quantity INT NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL,
        attachment_path VARCHAR(255) NULL,
        created_by VARCHAR(64) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (company_id),
        INDEX idx_employee (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 111: company_print_runs created\n";
} catch (Exception $e) {
    echo "Migration 111 failed: " . $e->getMessage() . "\n";
    exit(1);
}
