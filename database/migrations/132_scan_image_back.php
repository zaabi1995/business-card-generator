<?php
// 132_scan_image_back.php: back-side card image column for the Cardify Scan module
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();

    // Column-exists guard, same idempotent pattern as 045_employee_qr_redirect.php.
    $stmt = $pdo->query("SHOW COLUMNS FROM `scans` LIKE 'image_path_back'");
    $exists = $stmt && $stmt->rowCount() > 0;

    if (!$exists) {
        $pdo->exec("ALTER TABLE `scans`
            ADD COLUMN `image_path_back` VARCHAR(255) NULL
            AFTER `image_path`");
        echo "[132] Added scans.image_path_back\n";
    } else {
        echo "[132] scans.image_path_back already exists, skipped\n";
    }

    echo "Migration 132 OK\n";
} catch (Throwable $e) {
    echo "Migration 132 failed: " . $e->getMessage() . "\n";
    exit(1);
}
