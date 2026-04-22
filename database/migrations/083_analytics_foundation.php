<?php
/**
 * Migration 083: analytics foundation for Category I
 * (Cardify v2.0 sprint actions 236-260).
 *
 * card_events already captures: event_type, device_type, browser, os,
 * country_code, country_name, referrer, visitor_id (pre-existing).
 *
 * Adds:
 *   - card_events.wilayat              (action 240, Oman-level geo)
 *   - card_events.utm_source/medium/campaign (action 253)
 *   - card_events.ab_variant           (action 254)
 *
 * New tables:
 *   - analytics_goals     (action 250)
 *   - analytics_alerts    (action 258)
 *   - lead_captures       (action 252)
 *   - analytics_reports   (action 248, monthly auto-email)
 *   - card_ab_tests       (action 254)
 *
 * UI work ships in follow-up actions 660-684.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php'; // addColumnIfMissing()

try {
    $db = Database::getInstance();

    // card_events additional breakdown columns
    $cols = [
        'wilayat VARCHAR(60) NULL',
        'utm_source VARCHAR(80) NULL',
        'utm_medium VARCHAR(80) NULL',
        'utm_campaign VARCHAR(120) NULL',
        'ab_variant VARCHAR(16) NULL',
    ];
    foreach ($cols as $def) addColumnIfMissing($db, 'card_events', $def);

    // Index UTM source for attribution reports.
    $hasIdx = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_events'
           AND INDEX_NAME = 'idx_utm'"
    );
    if ((int)($hasIdx['c'] ?? 0) === 0) {
        $db->exec("ALTER TABLE card_events ADD INDEX idx_utm (utm_source, utm_medium, created_at)");
    }

    // Goals: per-company monthly tap/save/lead targets with progress.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS analytics_goals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            metric ENUM('taps','saves','wa_clicks','site_clicks','leads') NOT NULL,
            target_value INT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            achieved_value INT UNSIGNED NOT NULL DEFAULT 0,
            achieved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_period (company_id, metric, period_start, period_end),
            INDEX idx_company (company_id, period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Alerts: rule rows that fire when thresholds are crossed.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS analytics_alerts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            employee_id VARCHAR(36) NULL,
            rule_type ENUM('engagement_drop','engagement_spike','goal_reached','no_activity') NOT NULL,
            threshold_pct TINYINT NULL,
            window_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_triggered_at TIMESTAMP NULL,
            last_value_observed INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_company (company_id, enabled),
            INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Lead capture form submissions.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS lead_captures (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            employee_id VARCHAR(36) NULL,
            name VARCHAR(200) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(32) NULL,
            message TEXT NULL,
            custom_fields JSON NULL,
            utm_source VARCHAR(80) NULL,
            utm_medium VARCHAR(80) NULL,
            utm_campaign VARCHAR(120) NULL,
            referrer VARCHAR(1024) NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            status ENUM('new','contacted','qualified','converted','archived') NOT NULL DEFAULT 'new',
            notified_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_company (company_id, created_at),
            INDEX idx_employee (employee_id, created_at),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Monthly report subscription config.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS analytics_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            company_id VARCHAR(36) NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            cadence ENUM('monthly','weekly','daily') NOT NULL DEFAULT 'monthly',
            locale VARCHAR(5) NOT NULL DEFAULT 'en',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_sent_at TIMESTAMP NULL,
            last_sent_status ENUM('ok','failed') NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_company_email (company_id, recipient_email, cadence),
            INDEX idx_send (enabled, cadence, last_sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // A/B test config for same-employee two designs.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS card_ab_tests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id VARCHAR(36) NOT NULL,
            company_id VARCHAR(36) NOT NULL,
            variant_a_url VARCHAR(512) NOT NULL,
            variant_b_url VARCHAR(512) NOT NULL,
            split_pct TINYINT UNSIGNED NOT NULL DEFAULT 50,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ended_at TIMESTAMP NULL,
            winner ENUM('a','b','tie') NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_active_test (employee_id, ended_at),
            INDEX idx_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    echo "Migration 083: analytics foundation ready (card_events +5 cols +UTM index, 5 new tables)\n";
} catch (Exception $e) {
    echo "Migration 083 failed: " . $e->getMessage() . "\n";
    exit(1);
}
