-- Migration 058: Business Hours Section
-- Adds weekly open/close schedule + timezone to employee card sections.
-- Idempotent runner in 058_business_hours.php; raw DDL below.

CREATE TABLE IF NOT EXISTS `employee_business_hours` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `employee_card_sections`
    ADD COLUMN `hours_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `location_label`;

ALTER TABLE `employee_card_sections`
    ADD COLUMN `hours_timezone` VARCHAR(50) NOT NULL DEFAULT 'Asia/Muscat' AFTER `hours_enabled`;
