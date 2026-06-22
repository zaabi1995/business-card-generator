<?php
/**
 * Migration 125: wc_daily_bonus
 *
 * Idempotency ledger for one-time-per-day engagement bonuses (the Daily
 * Mission "predict all of today's matches" +5). One row per
 * (user_id, bonus_date, kind); the UNIQUE index is the single atomic winner,
 * so the award can only ever land once even under concurrent requests.
 *
 * Self-executing, idempotent, safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wc_daily_bonus (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL,
            bonus_date   DATE NOT NULL,
            kind         VARCHAR(32) NOT NULL DEFAULT 'daily_mission',
            points       INT NOT NULL DEFAULT 0,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_day_kind (user_id, bonus_date, kind),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "wc_daily_bonus ready\n";
} catch (Throwable $e) {
    fwrite(STDERR, "125_wc_daily_bonus failed: " . $e->getMessage() . "\n");
    exit(1);
}
