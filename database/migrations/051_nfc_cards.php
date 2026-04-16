<?php
/**
 * Migration 051: NFC Cards / Tag Provisioning
 *
 * Records every physical NFC tag programmed with a Cardify card URL.
 * Print shops use a phone with Web NFC (Chrome Android) to write tags
 * in bulk; this table tracks which employee got which tag and when.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = $pdo->query("SHOW TABLES LIKE 'nfc_cards'")->rowCount() > 0;

    if (!$exists) {
        $pdo->exec("CREATE TABLE `nfc_cards` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `employee_id` VARCHAR(36) NOT NULL,
            `company_id` VARCHAR(36) NOT NULL,
            `tag_uid` VARCHAR(64) NULL,
            `programmed` TINYINT(1) NOT NULL DEFAULT 0,
            `programmed_at` DATETIME NULL,
            `programmed_by_user_id` INT NULL,
            `batch_id` VARCHAR(32) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_employee` (`employee_id`),
            INDEX `idx_batch` (`batch_id`),
            INDEX `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[051] Created nfc_cards\n";
    } else {
        echo "[051] nfc_cards already exists - skipped\n";
    }

    echo "[051] Migration complete\n";
} catch (Exception $e) {
    echo "[051] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
