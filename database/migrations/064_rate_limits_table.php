<?php
/**
 * Migration 064: rate_limits table (atomic check+increment counter)
 *
 * Created for Codex round-2 findings — replaces "SELECT COUNT(*) then INSERT"
 * TOCTOU patterns in:
 *  - api/appointment/book.php (hourly cap race, Finding 4)
 *  - api/offer/redeem.php (wrong IP key + race, Finding 3)
 *  - api/lead.php, api/testimonial.php (same pattern)
 *
 * Uses a UNIQUE(action, ip, bucket) index so INSERT ... ON DUPLICATE KEY
 * UPDATE count=count+1 gives atomic check+increment in one statement.
 *
 * Idempotent.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $exists = $pdo->query("SHOW TABLES LIKE 'rate_limits'")->rowCount() > 0;

    if (!$exists) {
        $pdo->exec("CREATE TABLE `rate_limits` (
            `action`     VARCHAR(64)  NOT NULL,
            `ip`         VARCHAR(64)  NOT NULL,
            `bucket`     INT UNSIGNED NOT NULL COMMENT 'floor(time() / window_sec)',
            `count`      INT UNSIGNED NOT NULL DEFAULT 0,
            `window_sec` INT UNSIGNED NOT NULL DEFAULT 3600,
            `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`action`, `ip`, `bucket`),
            KEY `idx_updated_at` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[064] Created rate_limits\n";
    } else {
        echo "[064] rate_limits already exists — skipped\n";
    }

    return ['success' => true];
} catch (Throwable $e) {
    echo "[064] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
