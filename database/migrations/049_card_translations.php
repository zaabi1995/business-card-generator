<?php
/**
 * Migration 049: Per-card i18n
 *
 * Adds AR translation storage for:
 *   - bio_text_ar on employee_card_sections
 *   - employee_card_services_i18n  (service_id, locale, title, description)
 *   - employee_card_testimonials_i18n (testimonial_id, locale, name, quote)
 *
 * employees.{name,position}_{en,ar} already exist — nothing to add there.
 * Offers i18n is intentionally skipped (offers table not on main).
 *
 * Idempotent — safe to run multiple times.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // 1. bio_text_ar column
    $stmt = $pdo->query("SHOW COLUMNS FROM `employee_card_sections` LIKE 'bio_text_ar'");
    if (!$stmt || $stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE `employee_card_sections`
            ADD COLUMN `bio_text_ar` TEXT NULL AFTER `bio_text`");
        echo "[049] Added employee_card_sections.bio_text_ar\n";
    } else {
        echo "[049] employee_card_sections.bio_text_ar already exists — skipped\n";
    }

    // 2. services i18n table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_card_services_i18n` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[049] Ensured employee_card_services_i18n\n";

    // 3. testimonials i18n table
    $pdo->exec("CREATE TABLE IF NOT EXISTS `employee_card_testimonials_i18n` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[049] Ensured employee_card_testimonials_i18n\n";

    echo "[049] Migration complete\n";
} catch (Exception $e) {
    fwrite(STDERR, "[049] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
