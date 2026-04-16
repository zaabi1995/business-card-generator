<?php
/**
 * Migration 050: Custom Domains per Employee Card
 *
 * Pro-tier feature. Lets a card owner point their own domain
 * (e.g. ceo.acme.com) at their Cardify card via CNAME.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = $pdo->query("SHOW TABLES LIKE 'employee_custom_domains'")->rowCount() > 0;

    if (!$exists) {
        $pdo->exec("CREATE TABLE `employee_custom_domains` (
            `id` VARCHAR(36) PRIMARY KEY,
            `employee_id` VARCHAR(36) NOT NULL,
            `company_id` VARCHAR(36) NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `verified` TINYINT(1) NOT NULL DEFAULT 0,
            `ssl_status` ENUM('pending','active','failed') NOT NULL DEFAULT 'pending',
            `verification_token` VARCHAR(64) NULL,
            `last_checked_at` TIMESTAMP NULL DEFAULT NULL,
            `last_check_message` VARCHAR(255) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_domain` (`domain`),
            INDEX `idx_employee` (`employee_id`),
            INDEX `idx_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[050] Created employee_custom_domains\n";
    } else {
        echo "[050] employee_custom_domains already exists - skipped\n";
    }

    echo "[050] Migration complete\n";
} catch (Exception $e) {
    fwrite(STDERR, "[050] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
