<?php
/**
 * Migration 100: Employee-side change-request queue for fields that
 * require admin approval before persisting (currently: department).
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = $pdo->query("SHOW TABLES LIKE 'employee_edit_requests'")->rowCount() > 0;
    if (!$exists) {
        $pdo->exec("CREATE TABLE `employee_edit_requests` (
            `id` VARCHAR(36) PRIMARY KEY,
            `employee_id` VARCHAR(36) NOT NULL,
            `company_id` VARCHAR(36) NOT NULL,
            `field` VARCHAR(64) NOT NULL,
            `requested_value` VARCHAR(255) DEFAULT NULL,
            `requested_label` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `decided_by` VARCHAR(36) DEFAULT NULL,
            `decided_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_company_status` (`company_id`, `status`),
            INDEX `idx_employee` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[100] Created employee_edit_requests\n";
    } else {
        echo "[100] employee_edit_requests already exists, skipping\n";
    }
    echo "[100] Migration complete\n";
} catch (Exception $e) {
    echo "[100] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
