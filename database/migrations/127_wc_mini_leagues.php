<?php
/**
 * Migration 127: WC mini-leagues (private friend groups + their own board).
 *
 * Two tables:
 *   wc_leagues          - one row per league (name, share code, owner)
 *   wc_league_members   - membership join (UNIQUE league_id+user_id, idempotent)
 *
 * The share code is the viral hook: a friend shares
 * https://wc.cardify.om/join?l=CODE, a new person signs up and auto-joins.
 *
 * utf8mb4_unicode_ci everywhere (collation match, see CLAUDE.md). Self-executing,
 * idempotent, safe to re-run. Run on the VPS with /www/server/php/83/bin/php.
 */
require_once __DIR__ . '/../../config.php';

try {
    $pdo = Database::getInstance()->getConnection();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wc_leagues (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name        VARCHAR(60)  NOT NULL,
            code        VARCHAR(12)  NOT NULL,
            owner_id    INT UNSIGNED NOT NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wc_leagues_code (code),
            KEY idx_wc_leagues_owner (owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "wc_leagues ready\n";

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS wc_league_members (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            league_id   INT UNSIGNED NOT NULL,
            user_id     INT UNSIGNED NOT NULL,
            joined_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wc_league_member (league_id, user_id),
            KEY idx_wc_league_member_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "wc_league_members ready\n";
} catch (Throwable $e) {
    fwrite(STDERR, "127_wc_mini_leagues failed: " . $e->getMessage() . "\n");
    exit(1);
}
