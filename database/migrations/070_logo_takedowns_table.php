<?php
/**
 * Migration 070: logo_takedowns — takedown request audit log.
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    if ($pdo->query("SHOW TABLES LIKE 'logo_takedowns'")->rowCount() > 0) {
        echo "[070] logo_takedowns exists — skipped\n";
        return ['success' => true];
    }

    $pdo->exec("CREATE TABLE `logo_takedowns` (
        `id`                INT AUTO_INCREMENT PRIMARY KEY,
        `company_id`        INT NOT NULL,
        `requester_name`    VARCHAR(255) NOT NULL,
        `requester_email`   VARCHAR(255) NOT NULL,
        `requester_role`    VARCHAR(255) NULL,
        `claim_basis`       TEXT NOT NULL,
        `proof_url`         TEXT NULL,
        `related_urls`      TEXT NULL,
        `status`            ENUM('received','under_review','logo_hidden','resolved','rejected') NOT NULL DEFAULT 'received',
        `resolution_notes`  TEXT NULL,
        `decided_by`        VARCHAR(36) NULL,
        `decided_at`        DATETIME NULL,
        `ip_hash`           CHAR(64) NULL,
        `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (`company_id`),
        INDEX idx_status  (`status`),
        INDEX idx_created (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "[070] created logo_takedowns\n";
    return ['success' => true];
} catch (Throwable $e) {
    echo "[070] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
