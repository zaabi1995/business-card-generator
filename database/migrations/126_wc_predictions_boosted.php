<?php
/**
 * Migration 126: wc_predictions.boosted
 *
 * Lets a user mark ONE prediction per matchday as a 2x Boost. A boosted
 * prediction that scores correctly has its points DOUBLED by cron/wc_score.php.
 * Default 0 (no boost). One active boost per matchday is enforced in
 * api/wc-boost.php (clears the old one when a new pick is boosted).
 *
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();
    $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC)['db'] ?? null;

    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'wc_predictions' AND COLUMN_NAME = 'boosted'"
    );
    $check->execute([':db' => $dbName]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE wc_predictions ADD COLUMN boosted TINYINT NOT NULL DEFAULT 0 AFTER scored");
        echo "wc_predictions.boosted added\n";
    } else {
        echo "wc_predictions.boosted already present\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "126_wc_predictions_boosted failed: " . $e->getMessage() . "\n");
    exit(1);
}
