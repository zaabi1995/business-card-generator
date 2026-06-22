<?php
/**
 * Migration 128: instant match-result notifications.
 *
 * Adds:
 *   wc_matches.result_notified TINYINT NOT NULL DEFAULT 0
 *     Set to 1 by cron/wc_results.php after a finished match's full-time
 *     result has been pushed to all subscribers, so a result goes out ONCE.
 *   wc_users.notify_results TINYINT NOT NULL DEFAULT 1
 *     Per-user opt-out for the instant results layer (ON by default).
 *
 * Back-fill: any match already in state='post' at the time this migration
 * runs is marked result_notified=1, so turning the feature on does NOT blast
 * historical results for matches that finished before the feature existed.
 *
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();
    $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC)['db'] ?? null;

    $hasCol = function (string $table, string $col) use ($pdo, $dbName): bool {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $q->execute([':db' => $dbName, ':t' => $table, ':c' => $col]);
        return (int)$q->fetchColumn() > 0;
    };

    if (!$hasCol('wc_matches', 'result_notified')) {
        $pdo->exec("ALTER TABLE wc_matches ADD COLUMN result_notified TINYINT NOT NULL DEFAULT 0 AFTER state");
        echo "wc_matches.result_notified added\n";
        // Back-fill: do NOT notify for matches that already finished.
        $n = $pdo->exec("UPDATE wc_matches SET result_notified = 1 WHERE state = 'post'");
        echo "back-filled result_notified=1 on {$n} already-finished matches (no historical blast)\n";
    } else {
        echo "wc_matches.result_notified already present\n";
    }

    if (!$hasCol('wc_users', 'notify_results')) {
        $pdo->exec("ALTER TABLE wc_users ADD COLUMN notify_results TINYINT NOT NULL DEFAULT 1 AFTER notify_hour");
        echo "wc_users.notify_results added (default ON)\n";
    } else {
        echo "wc_users.notify_results already present\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "128_wc_result_notify failed: " . $e->getMessage() . "\n");
    exit(1);
}
