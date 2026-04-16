<?php
/**
 * Migration 058: Business Hours Section
 *
 * Adds "hours" section so employees can publish their weekly open/close
 * schedule on their digital card. The public render computes an Open/Closed
 * status in the card owner's configured IANA timezone (default Asia/Muscat).
 *
 * Creates:
 *   - employee_business_hours (per-day schedule, one row per day_of_week)
 *
 * Alters:
 *   - employee_card_sections: hours_enabled + hours_timezone
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // --- 1. Create employee_business_hours ------------------------------------
    $exists = $pdo->query("SHOW TABLES LIKE 'employee_business_hours'")->rowCount() > 0;
    if (!$exists) {
        $pdo->exec("CREATE TABLE `employee_business_hours` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` VARCHAR(36) NOT NULL,
            `day_of_week` ENUM('mon','tue','wed','thu','fri','sat','sun') NOT NULL,
            `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
            `open_time` TIME DEFAULT NULL,
            `close_time` TIME DEFAULT NULL,
            `break_start` TIME DEFAULT NULL,
            `break_end` TIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_employee_day` (`employee_id`, `day_of_week`),
            INDEX `idx_employee` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[058] Created employee_business_hours\n";
    } else {
        echo "[058] employee_business_hours already exists - skipped\n";
    }

    // --- 2. Extend employee_card_sections -------------------------------------
    $addColumn = function ($name, $ddl) use ($pdo) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'employee_card_sections'
               AND COLUMN_NAME = :col"
        );
        $stmt->execute(['col' => $name]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo "[058] employee_card_sections.$name already exists - skipped\n";
            return;
        }
        $pdo->exec("ALTER TABLE `employee_card_sections` ADD COLUMN $ddl");
        echo "[058] Added column $name\n";
    };

    $addColumn('hours_enabled', "`hours_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `location_label`");
    $addColumn('hours_timezone', "`hours_timezone` VARCHAR(50) NOT NULL DEFAULT 'Asia/Muscat' AFTER `hours_enabled`");

    echo "[058] Migration complete\n";
} catch (Exception $e) {
    echo "[058] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
