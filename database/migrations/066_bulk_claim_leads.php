<?php
/**
 * Migration 066: Bulk-claim cold-lead outreach
 *
 * Growth tool (super-admin only). Lets Ali paste a CSV of contacted-but-cold
 * leads, pre-creates a lightweight "unclaimed" employee record for each one,
 * and sends a WhatsApp magic-link so the lead can claim their card with a
 * single tap.
 *
 * Adds:
 *   1. employees.status — extends usage to recognise 'unclaimed'
 *      employees.created_via_bulk_claim  TINYINT(1) DEFAULT 0
 *   2. bulk_claim_batches — one row per admin CSV upload
 *   3. bulk_claim_leads   — one row per contact in the batch, carries the
 *      magic token, wa_status, per-lead funnel timestamps
 *
 * Idempotent — safe to re-run.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db  = Database::getInstance();
    $pdo = $db->getConnection();

    $dbNameRow = $pdo->query("SELECT DATABASE() AS db")->fetch(PDO::FETCH_ASSOC);
    $dbName    = $dbNameRow['db'] ?? null;

    // -------------------------------------------------------------------
    // 1. employees.created_via_bulk_claim + allow 'unclaimed' status
    //    (status is a free-form varchar(50) — no ENUM to extend)
    // -------------------------------------------------------------------
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :db
           AND TABLE_NAME   = 'employees'
           AND COLUMN_NAME  = 'created_via_bulk_claim'"
    );
    $check->execute([':db' => $dbName]);
    if ((int)$check->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `employees`
             ADD COLUMN `created_via_bulk_claim` TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'When 1, this employee was pre-created by the super-admin bulk claim flow and is awaiting claim.'"
        );
        echo "[066] Added employees.created_via_bulk_claim\n";
    } else {
        echo "[066] employees.created_via_bulk_claim already exists — skipped\n";
    }

    // -------------------------------------------------------------------
    // 2. bulk_claim_batches
    // -------------------------------------------------------------------
    $exists = $pdo->query("SHOW TABLES LIKE 'bulk_claim_batches'")->rowCount() > 0;
    if (!$exists) {
        $pdo->exec("CREATE TABLE `bulk_claim_batches` (
            `id`             VARCHAR(36)  NOT NULL,
            `admin_user_id`  VARCHAR(64)  DEFAULT NULL COMMENT 'super-admin user id / session identifier',
            `admin_label`    VARCHAR(255) DEFAULT NULL COMMENT 'friendly label for the batch (name of source list)',
            `company_id`     VARCHAR(36)  DEFAULT NULL COMMENT 'company_id used to host the preliminary employee records',
            `total`          INT UNSIGNED NOT NULL DEFAULT 0,
            `created`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'employee rows successfully pre-created',
            `sent`           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'WA messages successfully dispatched',
            `failed`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'WA dispatch failures',
            `opened`         INT UNSIGNED NOT NULL DEFAULT 0,
            `claimed`        INT UNSIGNED NOT NULL DEFAULT 0,
            `active`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'leads that claimed + added content (edited card)',
            `status`         VARCHAR(32)  NOT NULL DEFAULT 'draft' COMMENT 'draft | sending | sent | failed',
            `csv_snapshot`   MEDIUMTEXT   DEFAULT NULL,
            `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `sent_at`        TIMESTAMP    NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_status`     (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[066] Created bulk_claim_batches\n";
    } else {
        echo "[066] bulk_claim_batches already exists — skipped\n";
    }

    // -------------------------------------------------------------------
    // 3. bulk_claim_leads
    //    UNIQUE (phone) prevents double-outreach, but is scoped by the
    //    admin preview step (duplicates are flagged before insert).
    // -------------------------------------------------------------------
    $exists = $pdo->query("SHOW TABLES LIKE 'bulk_claim_leads'")->rowCount() > 0;
    if (!$exists) {
        $pdo->exec("CREATE TABLE `bulk_claim_leads` (
            `id`             VARCHAR(36)  NOT NULL,
            `batch_id`       VARCHAR(36)  NOT NULL,
            `name`           VARCHAR(255) NOT NULL,
            `phone`          VARCHAR(32)  NOT NULL,
            `company_name`   VARCHAR(255) DEFAULT NULL,
            `title`          VARCHAR(255) DEFAULT NULL,
            `email`          VARCHAR(255) DEFAULT NULL,
            `magic_token`    VARCHAR(64)  NOT NULL,
            `employee_id`    VARCHAR(36)  DEFAULT NULL,
            `card_url`       VARCHAR(512) DEFAULT NULL,
            `wa_status`      VARCHAR(32)  NOT NULL DEFAULT 'pending' COMMENT 'pending | sent | failed',
            `wa_error`       VARCHAR(512) DEFAULT NULL,
            `wa_message_id`  VARCHAR(128) DEFAULT NULL,
            `wa_sent_at`     TIMESTAMP    NULL DEFAULT NULL,
            `opened_at`      TIMESTAMP    NULL DEFAULT NULL,
            `claimed_at`     TIMESTAMP    NULL DEFAULT NULL,
            `activated_at`   TIMESTAMP    NULL DEFAULT NULL COMMENT 'first meaningful edit after claim',
            `token_used_at`  TIMESTAMP    NULL DEFAULT NULL COMMENT 'one-time-use guard',
            `expires_at`     TIMESTAMP    NULL DEFAULT NULL,
            `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_magic_token` (`magic_token`),
            UNIQUE KEY `uniq_phone`       (`phone`),
            KEY `idx_batch_id`            (`batch_id`),
            KEY `idx_wa_status`           (`wa_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "[066] Created bulk_claim_leads\n";
    } else {
        echo "[066] bulk_claim_leads already exists — skipped\n";
    }

    echo "[066] Migration complete\n";
    return ['success' => true];
} catch (Throwable $e) {
    echo "[066] ERROR: " . $e->getMessage() . "\n";
    return ['success' => false, 'errors' => [$e->getMessage()]];
}
