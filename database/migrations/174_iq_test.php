<?php
/**
 * Migration 174: the Cardify IQ test (cardify.om/iq).
 *
 * iq_users     people who sign in with an email or WhatsApp code (no company needed)
 * iq_attempts  one row per test taken, by a guest (private token) or a signed-in user
 * iq_answers   one row per question served; the server's clock decides what was in time
 * iq_payments  one-off full report and the monthly IQ Pro pass, paid through Paymob
 * iq_settings  prices and the fitted norms, editable without a deploy
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $db->exec("CREATE TABLE IF NOT EXISTS iq_users (
        id            VARCHAR(36) PRIMARY KEY,
        email         VARCHAR(190) NULL,
        phone         VARCHAR(32) NULL,
        display_name  VARCHAR(60) NOT NULL DEFAULT '',
        country       CHAR(2) NULL,
        birth_year    SMALLINT NULL,
        employee_id   VARCHAR(36) NULL,
        leaderboard   TINYINT(1) NOT NULL DEFAULT 1,
        pro_until     DATETIME NULL,
        created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL,
        UNIQUE KEY uq_iq_users_email (email),
        UNIQUE KEY uq_iq_users_phone (phone)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS iq_attempts (
        id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        public_id     CHAR(16) NOT NULL,
        user_id       VARCHAR(36) NULL,
        guest_token   CHAR(64) NULL,
        name          VARCHAR(60) NOT NULL DEFAULT '',
        age           TINYINT UNSIGNED NULL,
        country       CHAR(2) NULL,
        lang          VARCHAR(5) NOT NULL DEFAULT 'en',
        ip            VARCHAR(64) NULL,
        seed          CHAR(16) NOT NULL,
        status        ENUM('in_progress','done','abandoned') NOT NULL DEFAULT 'in_progress',
        started_ts    DOUBLE NOT NULL,
        started_at    DATETIME NOT NULL,
        finished_at   DATETIME NULL,
        correct       TINYINT UNSIGNED NULL,
        theta         DECIMAL(7,4) NULL,
        theta_se      DECIMAL(7,4) NULL,
        iq            SMALLINT NULL,
        domains       JSON NULL,
        focus_lost    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        report_paid   TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_iq_attempts_public (public_id),
        UNIQUE KEY uq_iq_attempts_guest (guest_token),
        KEY idx_iq_attempts_user (user_id, started_at),
        KEY idx_iq_attempts_ip (ip, started_at),
        KEY idx_iq_attempts_board (status, iq)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS iq_answers (
        attempt_id    BIGINT UNSIGNED NOT NULL,
        idx           TINYINT UNSIGNED NOT NULL,
        kind          VARCHAR(10) NOT NULL,
        level         TINYINT UNSIGNED NOT NULL,
        served_ts     DOUBLE NOT NULL,
        answered_ts   DOUBLE NULL,
        choice        TINYINT NULL,
        correct       TINYINT(1) NULL,
        late          TINYINT(1) NOT NULL DEFAULT 0,
        ms            INT UNSIGNED NULL,
        PRIMARY KEY (attempt_id, idx)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS iq_payments (
        id                VARCHAR(36) PRIMARY KEY,
        user_id           VARCHAR(36) NOT NULL,
        attempt_id        BIGINT UNSIGNED NULL,
        product           ENUM('report','pro_month') NOT NULL,
        amount            DECIMAL(10,3) NOT NULL,
        special_reference VARCHAR(80) NOT NULL,
        paymob_order_id   VARCHAR(40) NULL,
        transaction_id    VARCHAR(40) NULL,
        status            ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
        callback_data     JSON NULL,
        created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at           DATETIME NULL,
        UNIQUE KEY uq_iq_payments_ref (special_reference),
        KEY idx_iq_payments_user (user_id)
    ) $opts");

    $db->exec("CREATE TABLE IF NOT EXISTS iq_settings (
        `key`       VARCHAR(40) PRIMARY KEY,
        `value`     TEXT NULL,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $opts");

    echo "Migration 174: IQ test tables created\n";
} catch (Exception $e) {
    echo "Migration 174 failed: " . $e->getMessage() . "\n";
    exit(1);
}
