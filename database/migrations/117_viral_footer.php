<?php
/**
 * Migration 117: Viral "Made with Cardify" footer
 *
 * Adds per-card opt-out flag (Pro tier) for the viral footer shown on every
 * public digital card, extends the card_events ENUM with the new click +
 * view event types, and provisions a signup leads table for the /claim
 * landing page.
 *
 * Changes:
 *   1. `employees.hide_cardify_branding` TINYINT(1) DEFAULT 0
 *   2. `card_events.event_type` ENUM += 'viral_footer_click', 'viral_footer_view'
 *   3. `cardify_signup_leads` table (phone/email capture from /claim)
 *
 * Idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    // -------------------------------------------------------------------
    // 1. employees.hide_cardify_branding
    // -------------------------------------------------------------------
    $dbNameRow = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC);
    $dbName    = $dbNameRow['db'] ?? null;

    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db
           AND TABLE_NAME   = 'employees'
           AND COLUMN_NAME  = 'hide_cardify_branding'"
    );
    $check->execute([':db' => $dbName]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `employees`
             ADD COLUMN `hide_cardify_branding` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'When 1 (Pro only), hides the Made with Cardify viral footer on the public card.'"
        );
        echo "[117] Added employees.hide_cardify_branding\n";
    } else {
        echo "[117] employees.hide_cardify_branding already exists — skipped\n";
    }

    // -------------------------------------------------------------------
    // 2. Extend card_events.event_type ENUM with viral_footer_click + view
    // -------------------------------------------------------------------
    $col = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'card_events'
           AND COLUMN_NAME  = 'event_type'"
    )->fetchColumn();

    $needsClick = $col && strpos($col, "'viral_footer_click'") === false;
    $needsView  = $col && strpos($col, "'viral_footer_view'")  === false;

    if ($needsClick || $needsView) {
        // Preserve every value already in the ENUM so we never drop prior
        // migrations' extensions (055 short_link_click, 058 product_order_click, ...).
        // Parse the current set and append what's missing.
        preg_match_all("/'([^']+)'/", (string)$col, $m);
        $existing = $m[1] ?? [];
        $desired  = array_merge($existing, array_values(array_filter([
            $needsClick ? 'viral_footer_click' : null,
            $needsView  ? 'viral_footer_view'  : null,
        ])));
        // Deduplicate, preserve order
        $desired = array_values(array_unique($desired));
        $enumList = "'" . implode("','", array_map(function ($v) {
            return str_replace("'", "''", $v);
        }, $desired)) . "'";
        $pdo->exec("ALTER TABLE card_events MODIFY COLUMN event_type ENUM($enumList) NOT NULL");
        echo "[117] card_events.event_type extended with viral_footer_click + viral_footer_view\n";
    } else {
        echo "[117] card_events.event_type already contains viral_footer_* — skipped\n";
    }

    // -------------------------------------------------------------------
    // 3. cardify_signup_leads (public claim-page capture)
    // -------------------------------------------------------------------
    $exists = $pdo->query("SHOW TABLES LIKE 'cardify_signup_leads'")->rowCount() > 0;
    if (!$exists) {
        $pdo->exec("CREATE TABLE `cardify_signup_leads` (
            `id`              VARCHAR(36)  NOT NULL,
            `phone`           VARCHAR(32)  DEFAULT NULL,
            `email`           VARCHAR(255) DEFAULT NULL,
            `source`          VARCHAR(64)  NOT NULL DEFAULT 'viral_footer',
            `utm_source`      VARCHAR(64)  DEFAULT NULL,
            `utm_medium`      VARCHAR(64)  DEFAULT NULL,
            `utm_campaign`    VARCHAR(64)  DEFAULT NULL,
            `utm_content`     VARCHAR(128) DEFAULT NULL COMMENT 'source employee_id when available',
            `ref_employee_id` VARCHAR(36)  DEFAULT NULL,
            `locale`          VARCHAR(8)   DEFAULT NULL,
            `ip_address`      VARCHAR(64)  DEFAULT NULL,
            `user_agent`      VARCHAR(512) DEFAULT NULL,
            `status`          VARCHAR(32)  NOT NULL DEFAULT 'new',
            `claimed_at`      TIMESTAMP    NULL DEFAULT NULL,
            `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_phone`      (`phone`),
            KEY `idx_email`      (`email`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_source`     (`source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[117] Created cardify_signup_leads\n";
    } else {
        echo "[117] cardify_signup_leads already exists — skipped\n";
    }

    echo "[117] Migration complete\n";
    return ['success' => true];
} catch (Throwable $e) {
    echo "[117] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
