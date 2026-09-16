<?php
/**
 * The MHD card fulfilment flow: state, evidence, and per-division routing.
 *
 * A card request now travels submitted -> approved -> quoted -> po_received ->
 * in_production -> dispatched -> delivered, and every move is recorded in
 * card_request_events with who did it and the proof. The department columns let
 * each division carry its own approver CC, its own BHD-ERP client and its own
 * rate without a code change.
 *
 * Every column is additive and defaulted, so tenants that do not use the flow
 * are untouched.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    $add = function (string $table, string $col, string $ddl) use ($db) {
        if (!$db->columnExists($table, $col)) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
            echo "Migration 159: {$table}.{$col} added\n";
        } else {
            echo "Migration 159: {$table}.{$col} already present\n";
        }
    };

    $add('card_requests', 'job_ref',          "`job_ref` VARCHAR(16) NULL");
    $add('card_requests', 'erp_order_id',     "`erp_order_id` INT NULL");
    $add('card_requests', 'po_number',        "`po_number` VARCHAR(32) NULL");
    $add('card_requests', 'po_file',          "`po_file` VARCHAR(255) NULL");
    $add('card_requests', 'quantity_ordered', "`quantity_ordered` INT NOT NULL DEFAULT 200");

    $add('departments', 'head_email',      "`head_email` VARCHAR(190) NULL");
    $add('departments', 'erp_client_name', "`erp_client_name` VARCHAR(190) NULL");
    $add('departments', 'card_unit_price', "`card_unit_price` DECIMAL(10,3) NOT NULL DEFAULT 0.030");

    $add('users', 'viewer_scope', "`viewer_scope` VARCHAR(16) NOT NULL DEFAULT 'division'");

    $db->exec("CREATE TABLE IF NOT EXISTS `card_request_events` (
        `id`         CHAR(36) NOT NULL PRIMARY KEY,
        `request_id` CHAR(36) NOT NULL,
        `company_id` CHAR(36) NOT NULL,
        `from_state` VARCHAR(24) NULL,
        `to_state`   VARCHAR(24) NOT NULL,
        `actor`      VARCHAR(190) NULL,
        `evidence`   TEXT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_cre_request` (`request_id`),
        INDEX `idx_cre_company_state` (`company_id`, `to_state`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Migration 159: card_request_events ready\n";

    // CREATE INDEX IF NOT EXISTS is not portable across the MySQL/MariaDB versions
    // this runs on, so check the catalogue instead of relying on the syntax.
    $idx = $db->fetchOne(
        "SELECT COUNT(*) c FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = 'card_requests'
            AND index_name = 'idx_card_requests_job_ref'");
    if ((int)($idx['c'] ?? 0) === 0) {
        $db->exec("CREATE UNIQUE INDEX `idx_card_requests_job_ref` ON `card_requests` (`job_ref`)");
        echo "Migration 159: job_ref unique index added\n";
    } else {
        echo "Migration 159: job_ref unique index already present\n";
    }

    echo "Migration 159: done\n";
} catch (Exception $e) {
    echo "Migration 159 failed: " . $e->getMessage() . "\n";
    exit(1);
}
