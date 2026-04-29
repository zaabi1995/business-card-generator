<?php
/**
 * Migration 075: Persist phone-backfill prompt skip counter.
 *
 * BHD-224 follow-up — the dashboard banner that asks legacy admins to add
 * a WhatsApp number was previously dismissed via session only, so it
 * re-appeared on every login. CEO scope was "dismissible after 3 skips"
 * — once the admin has hit "Not now" 3 times we stop nagging.
 *
 * Adds:
 *   - companies.phone_backfill_skips TINYINT(1) NOT NULL DEFAULT 0
 *
 * Idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $dbNameRow = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC);
    $dbName    = $dbNameRow['db'] ?? null;

    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db
           AND TABLE_NAME   = 'companies'
           AND COLUMN_NAME  = 'phone_backfill_skips'"
    );
    $check->execute([':db' => $dbName]);

    if ((int) $check->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `companies`
             ADD COLUMN `phone_backfill_skips` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'Times admin dismissed phone backfill banner; suppressed at 3 (BHD-224)'
             AFTER `phone`"
        );
        echo "[075] Added companies.phone_backfill_skips\n";
    } else {
        echo "[075] companies.phone_backfill_skips already exists — skipped\n";
    }

    echo "[075] Migration complete\n";
    return ['success' => true];
} catch (Throwable $e) {
    echo "[075] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
