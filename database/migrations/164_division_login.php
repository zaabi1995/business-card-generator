<?php
/**
 * Migration 164: let a division approver sign in with their own email.
 *
 * They have no account and no password: the only thing that proves who they
 * are is the mailbox the approvals already go to. A one-time link is sent
 * there, and holding it is what signs them in to their own division's
 * settings, nothing else.
 *
 * Also records who changed what on a division, because these settings decide
 * where a card request goes and which ERP account it is billed to.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $db->exec("CREATE TABLE IF NOT EXISTS `division_login_tokens` (
        `id`            CHAR(36) NOT NULL PRIMARY KEY,
        `company_id`    CHAR(36) NOT NULL,
        `department_id` CHAR(36) NOT NULL,
        `email`         VARCHAR(190) NOT NULL,
        `token_hash`    CHAR(64) NOT NULL,
        `expires_at`    DATETIME NOT NULL,
        `used_at`       DATETIME NULL,
        `ip`            VARCHAR(45) NULL,
        `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_dlt_hash` (`token_hash`),
        KEY `idx_dlt_dept` (`department_id`),
        KEY `idx_dlt_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 164: division_login_tokens ready\n";

    $db->exec("CREATE TABLE IF NOT EXISTS `division_setting_changes` (
        `id`            CHAR(36) NOT NULL PRIMARY KEY,
        `company_id`    CHAR(36) NOT NULL,
        `department_id` CHAR(36) NOT NULL,
        `actor`         VARCHAR(190) NULL,
        `field`         VARCHAR(64) NOT NULL,
        `old_value`     TEXT NULL,
        `new_value`     TEXT NULL,
        `ip`            VARCHAR(45) NULL,
        `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_dsc_dept` (`department_id`),
        KEY `idx_dsc_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 164: division_setting_changes ready\n";

    echo "Migration 164: done\n";
} catch (Exception $e) {
    echo "Migration 164 failed: " . $e->getMessage() . "\n";
    exit(1);
}
