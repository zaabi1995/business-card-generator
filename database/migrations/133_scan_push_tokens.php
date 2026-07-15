<?php
// 133_scan_push_tokens.php: Expo push tokens for the Cardify Scan app.
// One row per device token so future server pushes (e.g. "someone claimed
// your card") can reach the signed-in employee. Registration is best-effort
// from the app (api/scan/register-push.php); the send side lands later.
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        token VARCHAR(255) NOT NULL,
        platform VARCHAR(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_token (token),
        KEY idx_employee (employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "Migration 133 OK\n";
} catch (Throwable $e) {
    echo "Migration 133 failed: " . $e->getMessage() . "\n";
    exit(1);
}
