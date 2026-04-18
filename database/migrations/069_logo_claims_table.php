<?php
/**
 * Migration 069: logo_claims — claim attempts audit log.
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = $pdo->query("SHOW TABLES LIKE 'logo_claims'")->rowCount() > 0;
    if ($exists) {
        echo "[069] logo_claims exists — skipped\n";
        return ['success' => true];
    }

    $pdo->exec("CREATE TABLE `logo_claims` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `company_id`      INT NOT NULL,
        `user_id`         VARCHAR(36) NOT NULL,
        `proof_type`      ENUM('domain_email','cr_document','domain_dns','other') NOT NULL,
        `proof_url`       TEXT NULL,
        `proof_email`     VARCHAR(255) NULL,
        `role_at_company` VARCHAR(64) NULL,
        `note`            TEXT NULL,
        `auto_verified`   TINYINT(1) NOT NULL DEFAULT 0,
        `status`          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `decided_by`      VARCHAR(36) NULL,
        `decided_at`      DATETIME NULL,
        `decision_notes`  TEXT NULL,
        `ip_hash`         CHAR(64) NULL,
        `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (`company_id`),
        INDEX idx_user    (`user_id`),
        INDEX idx_status  (`status`),
        INDEX idx_created (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "[069] created logo_claims\n";
    return ['success' => true];
} catch (Throwable $e) {
    echo "[069] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
