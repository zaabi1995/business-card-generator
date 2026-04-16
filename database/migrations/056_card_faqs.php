<?php
/**
 * Migration 056: Card FAQ Section
 *
 * Adds an expandable Q&A "FAQ" section to public employee cards.
 *   - employee_card_faqs     : one row per Q&A (id, employee_id, company_id,
 *                              question EN + optional AR, answer EN + optional AR,
 *                              position, created_at).
 *   - employee_card_sections : new `faq_enabled` TINYINT flag.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // ----- 1) Create employee_card_faqs (idempotent) -----------------------
    $exists = $pdo->query("SHOW TABLES LIKE 'employee_card_faqs'")->rowCount() > 0;
    if (!$exists) {
        $pdo->exec("CREATE TABLE `employee_card_faqs` (
            `id` VARCHAR(36) PRIMARY KEY,
            `employee_id` VARCHAR(36) NOT NULL,
            `company_id` VARCHAR(36) NOT NULL,
            `question` VARCHAR(500) NOT NULL,
            `answer` TEXT NOT NULL,
            `question_ar` VARCHAR(500) DEFAULT NULL,
            `answer_ar` TEXT DEFAULT NULL,
            `position` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`company_id`)  REFERENCES `companies`(`id`)  ON DELETE CASCADE,
            INDEX `idx_emp_pos` (`employee_id`, `position`),
            INDEX `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[056] Created employee_card_faqs\n";
    } else {
        echo "[056] employee_card_faqs already exists - skipped\n";

        // Defensive: if table exists but AR columns missing (older branch), add them.
        foreach (['question_ar' => "`question_ar` VARCHAR(500) DEFAULT NULL",
                  'answer_ar'   => "`answer_ar` TEXT DEFAULT NULL"] as $col => $ddl) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'employee_card_faqs'
                   AND COLUMN_NAME = :col"
            );
            $stmt->execute(['col' => $col]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `employee_card_faqs` ADD COLUMN $ddl");
                echo "[056] Added employee_card_faqs.$col\n";
            }
        }
    }

    // ----- 2) Add faq_enabled to employee_card_sections --------------------
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'employee_card_sections'
           AND COLUMN_NAME = 'faq_enabled'"
    );
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `employee_card_sections`
             ADD COLUMN `faq_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `location_label`"
        );
        echo "[056] Added employee_card_sections.faq_enabled\n";
    } else {
        echo "[056] employee_card_sections.faq_enabled already exists - skipped\n";
    }

    echo "[056] Migration complete\n";
} catch (Exception $e) {
    echo "[056] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
