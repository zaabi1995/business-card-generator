<?php
/**
 * Migration 123: Apple Wallet pass tables for the World Cup hub.
 *
 *   wc_wallet_passes          one row per issued .pkpass (serial + auth token).
 *   wallet_device_registrations  PassKit device<->serial registrations
 *                                + the APNs push token for daily updates.
 *
 * The PassKit web service (index.php /wc-wallet/v1/...) registers a device
 * for a serial here on install; cron/wc_wallet_update.php pushes to every
 * registered device when the pass content changes.
 *
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wc_wallet_passes (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       INT NOT NULL,
            serial        VARCHAR(64) NOT NULL,
            auth_token    VARCHAR(64) NOT NULL,
            updated_tag   VARCHAR(32) NOT NULL DEFAULT '0',
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_serial (serial),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "wc_wallet_passes ready\n";

    // The table may pre-exist from an earlier schema (platform/auth_token char(32),
    // no updated_tag/updated_at). Bring it up to spec, additively + idempotently.
    $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC)['db'] ?? null;
    $hasCol = function (string $col) use ($pdo, $dbName): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=:db AND TABLE_NAME='wc_wallet_passes' AND COLUMN_NAME=:c");
        $st->execute([':db'=>$dbName, ':c'=>$col]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$hasCol('updated_tag')) {
        $pdo->exec("ALTER TABLE wc_wallet_passes ADD COLUMN updated_tag VARCHAR(32) NOT NULL DEFAULT '0'");
        echo "  + updated_tag\n";
    }
    if (!$hasCol('updated_at')) {
        $pdo->exec("ALTER TABLE wc_wallet_passes ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        echo "  + updated_at\n";
    }
    // Widen auth_token (40-hex tokens need >32 chars).
    $pdo->exec("ALTER TABLE wc_wallet_passes MODIFY auth_token VARCHAR(64) NOT NULL");
    // Make platform optional for apple-only WC passes (default apple if the column exists).
    if ($hasCol('platform')) {
        try { $pdo->exec("ALTER TABLE wc_wallet_passes MODIFY platform ENUM('apple','google') NOT NULL DEFAULT 'apple'"); } catch (Throwable $e) {}
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wallet_device_registrations (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            device_lib_id   VARCHAR(128) NOT NULL,
            pass_serial     VARCHAR(64) NOT NULL,
            push_token      VARCHAR(255) NOT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_device_serial (device_lib_id, pass_serial),
            KEY idx_serial (pass_serial)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "wallet_device_registrations ready\n";
} catch (Throwable $e) {
    fwrite(STDERR, "123_wc_wallet_passes failed: " . $e->getMessage() . "\n");
    exit(1);
}
