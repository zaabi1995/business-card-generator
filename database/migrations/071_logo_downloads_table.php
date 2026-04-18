<?php
/**
 * Migration 071: logo_downloads — per-download analytics.
 * IP/UA hashed for privacy. Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    if ($pdo->query("SHOW TABLES LIKE 'logo_downloads'")->rowCount() > 0) {
        echo "[071] logo_downloads exists — skipped\n";
        return ['success' => true];
    }

    $pdo->exec("CREATE TABLE `logo_downloads` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `company_id`       INT NOT NULL,
        `format`           ENUM('svg','png_512','png_1024','png_2048','webp','zip') NOT NULL,
        `ip_hash`          CHAR(64) NOT NULL,
        `user_agent_hash`  CHAR(64) NULL,
        `referrer`         VARCHAR(500) NULL,
        `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_company (`company_id`),
        INDEX idx_format  (`format`),
        INDEX idx_created (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "[071] created logo_downloads\n";
    return ['success' => true];
} catch (Throwable $e) {
    echo "[071] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
