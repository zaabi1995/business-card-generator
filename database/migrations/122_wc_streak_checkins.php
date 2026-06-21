<?php
/**
 * Migration 122: daily-streak gamification for the World Cup hub.
 *
 * Adds:
 *   - wc_checkins(user_id, checkin_date)  UNIQUE(user_id, checkin_date)
 *     One row per user per day. The UNIQUE index is the single atomic
 *     winner that makes the check-in idempotent (reserve before award).
 *   - wc_users.streak_count  INT   current consecutive-day streak
 *   - wc_users.streak_best   INT   best streak ever reached
 *   - wc_users.last_checkin  DATE  last day the user checked in
 *
 * Streak points (+1/day, +5 once at a 7-day streak) are awarded into
 * bonus_points AND points_cache so they survive cron/wc_score.php, which
 * recomputes points_cache = SUM(predictions.points) + bonus_points.
 *
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();
    $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC)['db'] ?? null;

    // 1) wc_checkins table
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wc_checkins (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            checkin_date DATE NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_day (user_id, checkin_date),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "wc_checkins ready\n";

    // 2) streak columns on wc_users
    $cols = [
        'streak_count' => "ALTER TABLE wc_users ADD COLUMN streak_count INT NOT NULL DEFAULT 0",
        'streak_best'  => "ALTER TABLE wc_users ADD COLUMN streak_best INT NOT NULL DEFAULT 0",
        'last_checkin' => "ALTER TABLE wc_users ADD COLUMN last_checkin DATE NULL DEFAULT NULL",
    ];
    foreach ($cols as $name => $ddl) {
        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'wc_users' AND COLUMN_NAME = :c"
        );
        $check->execute([':db' => $dbName, ':c' => $name]);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec($ddl);
            echo "wc_users.{$name} added\n";
        } else {
            echo "wc_users.{$name} already present\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "122_wc_streak_checkins failed: " . $e->getMessage() . "\n");
    exit(1);
}
