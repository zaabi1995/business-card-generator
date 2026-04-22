<?php
/**
 * Migration 084: notifications foundation for Category O
 * (Cardify v2.0 sprint actions 371-390).
 *
 * New tables:
 *   - notifications            (action 377, in-app bell feed)
 *   - notification_preferences (actions 375, 388, per-user per-type
 *                               on/off + channel)
 *   - notification_dispatches  (actions 378, 390, per-send log,
 *                               opens + clicks tracked for email)
 *   - system_banners           (action 380, status-page / outage)
 *
 * Existing includes/Notifier.php stays; this migration just gives it
 * the tables to write to. Per-channel template files already exist
 * under includes/notifications/templates/ (action 068-080, 15 EN + 15
 * AR).
 *
 * UI wiring (bell header, preferences page, banner renderer) ships in
 * follow-up actions 755-770.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php'; // addColumnIfMissing()

try {
    $db = Database::getInstance();

    // In-app bell feed.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            recipient_id VARCHAR(36) NOT NULL COMMENT 'user id (company admin, employee, or print shop user)',
            recipient_type ENUM('company_admin','employee','print_shop','super_admin') NOT NULL,
            company_id VARCHAR(36) NULL,
            event_type VARCHAR(60) NOT NULL,
            title VARCHAR(160) NOT NULL,
            body TEXT NULL,
            action_url VARCHAR(500) NULL,
            metadata JSON NULL,
            read_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recipient (recipient_id, read_at, created_at),
            INDEX idx_event (event_type, created_at),
            INDEX idx_company (company_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Per-user per-event toggle + preferred channel.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS notification_preferences (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(36) NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            channel_email TINYINT(1) NOT NULL DEFAULT 1,
            channel_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
            channel_inapp TINYINT(1) NOT NULL DEFAULT 1,
            digest_mode ENUM('instant','daily','weekly','off') NOT NULL DEFAULT 'instant',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_event (user_id, event_type),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // Dispatch log, one row per channel per event.
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS notification_dispatches (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            notification_id BIGINT UNSIGNED NULL,
            recipient_id VARCHAR(36) NOT NULL,
            recipient_email VARCHAR(255) NULL,
            recipient_phone VARCHAR(32) NULL,
            event_type VARCHAR(60) NOT NULL,
            channel ENUM('email','whatsapp','inapp') NOT NULL,
            template_key VARCHAR(80) NULL,
            locale VARCHAR(5) NOT NULL DEFAULT 'en',
            status ENUM('queued','sent','delivered','opened','clicked','bounced','failed') NOT NULL DEFAULT 'queued',
            provider_id VARCHAR(120) NULL,
            sent_at TIMESTAMP NULL,
            opened_at TIMESTAMP NULL,
            clicked_at TIMESTAMP NULL,
            error TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event (event_type, created_at),
            INDEX idx_recipient (recipient_id, created_at),
            INDEX idx_status (status, created_at),
            INDEX idx_notification (notification_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    // System banners (outage, maintenance, feature announcements).
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS system_banners (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            severity ENUM('info','success','warn','critical') NOT NULL DEFAULT 'info',
            message_en TEXT NOT NULL,
            message_ar TEXT NULL,
            cta_url VARCHAR(500) NULL,
            cta_label_en VARCHAR(120) NULL,
            cta_label_ar VARCHAR(120) NULL,
            audience ENUM('all','company_admins','employees','print_shops') NOT NULL DEFAULT 'all',
            starts_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ends_at TIMESTAMP NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by VARCHAR(36) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_active (enabled, starts_at, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    echo "Migration 084: notifications + notification_preferences + notification_dispatches + system_banners tables created\n";
} catch (Exception $e) {
    echo "Migration 084 failed: " . $e->getMessage() . "\n";
    exit(1);
}
