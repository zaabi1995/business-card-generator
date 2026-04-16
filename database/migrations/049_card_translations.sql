-- Migration 049: Per-card i18n
-- Adds AR translations for bio, services, testimonials.
-- employees.{name,position}_{en,ar} already exist; nothing to add there.
-- Idempotent.

ALTER TABLE `employee_card_sections`
    ADD COLUMN IF NOT EXISTS `bio_text_ar` TEXT NULL AFTER `bio_text`;

CREATE TABLE IF NOT EXISTS `employee_card_services_i18n` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `service_id` VARCHAR(36) NOT NULL,
    `locale` ENUM('en','ar') NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_service_locale` (`service_id`, `locale`),
    INDEX `idx_service` (`service_id`),
    FOREIGN KEY (`service_id`) REFERENCES `employee_card_services`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_card_testimonials_i18n` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `testimonial_id` VARCHAR(36) NOT NULL,
    `locale` ENUM('en','ar') NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `quote` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_testimonial_locale` (`testimonial_id`, `locale`),
    INDEX `idx_testimonial` (`testimonial_id`),
    FOREIGN KEY (`testimonial_id`) REFERENCES `employee_card_testimonials`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
