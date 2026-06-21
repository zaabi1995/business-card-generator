<?php
/**
 * Migration 121: wc_users.notify_hour
 *
 * Lets each World Cup signup choose the local hour (0-23, in their timezone)
 * they want their daily reminder. Default 10 (10am, the previous fixed time).
 *
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();
    $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC)['db'] ?? null;

    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'wc_users' AND COLUMN_NAME = 'notify_hour'"
    );
    $check->execute([':db' => $dbName]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE wc_users ADD COLUMN notify_hour TINYINT NOT NULL DEFAULT 10 AFTER tz");
        echo "wc_users.notify_hour added\n";
    } else {
        echo "wc_users.notify_hour already present\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "121_wc_notify_hour failed: " . $e->getMessage() . "\n");
    exit(1);
}
