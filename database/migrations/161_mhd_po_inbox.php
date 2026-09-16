<?php
/**
 * Migration 161: the purchase-order intake's memory.
 *
 * mhd_po_seen records every message the poller has examined, so a mailbox scan
 * is idempotent and a reply is never filed twice. It also keeps the outcome,
 * which is how you find out why a purchase order that was sent did not land.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec("CREATE TABLE IF NOT EXISTS `mhd_po_seen` (
        `message_key` VARCHAR(190) NOT NULL,
        `subject`     VARCHAR(255) NULL,
        `job_ref`     VARCHAR(24)  NULL,
        `outcome`     VARCHAR(190) NULL,
        `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`message_key`),
        KEY `idx_po_seen_ref` (`job_ref`),
        KEY `idx_po_seen_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Migration 161: mhd_po_seen ready\n";
} catch (Exception $e) {
    echo "Migration 161 failed: " . $e->getMessage() . "\n";
    exit(1);
}
