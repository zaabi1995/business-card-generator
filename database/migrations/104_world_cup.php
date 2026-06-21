<?php
/**
 * Migration 104: Cardify World Cup 2026 campaign (wc.cardify.om).
 * Signup hub + predictions game + leaderboard + wallet passes.
 * Signups also mirror into cardify_signup_leads (source='world-cup') so every
 * registrant lands in the Cardify backend as a lead.
 */
require_once __DIR__ . '/../../config.php';

$db  = Database::getInstance();
$pdo = $db->getConnection();

$pdo->exec("CREATE TABLE IF NOT EXISTS `wc_users` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone`               VARCHAR(20)  NOT NULL,
    `name`                VARCHAR(120) NOT NULL,
    `language`            VARCHAR(8)   NOT NULL DEFAULT 'en',
    `tz`                  VARCHAR(48)  NOT NULL DEFAULT 'Asia/Muscat',
    `country`             VARCHAR(4)   DEFAULT NULL,
    `status`              VARCHAR(16)  NOT NULL DEFAULT 'active',
    `points_cache`        INT          NOT NULL DEFAULT 0,
    `unsub_token`         CHAR(32)     NOT NULL,
    `share_bonus_awarded` TINYINT(1)   NOT NULL DEFAULT 0,
    `lead_id`             VARCHAR(36)  DEFAULT NULL,
    `ip_address`          VARCHAR(64)  DEFAULT NULL,
    `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `verified_at`         TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_phone` (`phone`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[104] wc_users ready\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `wc_matches` (
    `espn_id`     VARCHAR(32) NOT NULL,
    `stage`       VARCHAR(32) DEFAULT NULL,
    `home`        VARCHAR(80) DEFAULT NULL,
    `away`        VARCHAR(80) DEFAULT NULL,
    `kickoff_utc` DATETIME    DEFAULT NULL,
    `home_score`  INT         DEFAULT NULL,
    `away_score`  INT         DEFAULT NULL,
    `state`       VARCHAR(12) DEFAULT NULL,
    `updated_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`espn_id`),
    KEY `idx_kickoff` (`kickoff_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[104] wc_matches ready\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `wc_predictions` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `match_id`   VARCHAR(32) NOT NULL,
    `pick`       ENUM('home','draw','away') DEFAULT NULL,
    `pred_home`  TINYINT UNSIGNED DEFAULT NULL,
    `pred_away`  TINYINT UNSIGNED DEFAULT NULL,
    `points`     INT NOT NULL DEFAULT 0,
    `scored`     TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_match` (`user_id`,`match_id`),
    KEY `idx_match` (`match_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[104] wc_predictions ready\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `wc_share_proofs` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `image_path` VARCHAR(255) DEFAULT NULL,
    `status`     VARCHAR(16) NOT NULL DEFAULT 'pending',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[104] wc_share_proofs ready\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `wc_wallet_passes` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `serial`     VARCHAR(64) NOT NULL,
    `platform`   ENUM('apple','google') NOT NULL,
    `auth_token` CHAR(32) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_serial` (`serial`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[104] wc_wallet_passes ready\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `wc_wallet_devices` (
    `device_lib_id` VARCHAR(64) NOT NULL,
    `pass_serial`   VARCHAR(64) NOT NULL,
    `push_token`    VARCHAR(255) DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`device_lib_id`,`pass_serial`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "[104] wc_wallet_devices ready\n";
echo "[104] World Cup tables migration complete\n";
