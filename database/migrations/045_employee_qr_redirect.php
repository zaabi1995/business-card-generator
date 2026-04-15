<?php
/**
 * Migration 043: Dynamic QR redirect URL on employees
 *
 * Adds an optional `qr_redirect_url` column. When set, qr.php?i={id} will
 * 302-redirect to this URL instead of streaming the VCF, giving owners the
 * ability to change a printed card's QR destination after it has been printed.
 *
 * Idempotent — safe to run multiple times.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Does the column already exist?
    $stmt = $pdo->query("SHOW COLUMNS FROM `employees` LIKE 'qr_redirect_url'");
    $exists = $stmt && $stmt->rowCount() > 0;

    if (!$exists) {
        $pdo->exec("ALTER TABLE `employees`
            ADD COLUMN `qr_redirect_url` VARCHAR(1024) NULL DEFAULT NULL
            AFTER `address_ar`");
        echo "[043] Added employees.qr_redirect_url\n";
    } else {
        echo "[043] employees.qr_redirect_url already exists — skipped\n";
    }

    echo "[043] Migration complete\n";
} catch (Exception $e) {
    fwrite(STDERR, "[043] Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
