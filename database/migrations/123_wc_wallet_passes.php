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
