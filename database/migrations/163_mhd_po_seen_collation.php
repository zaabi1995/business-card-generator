<?php
/**
 * Migration 163: give mhd_po_seen the collation the rest of the schema uses.
 *
 * Migration 161 created it with no COLLATE clause, so MariaDB gave it
 * utf8mb4_general_ci while card_requests and every other table here are
 * utf8mb4_unicode_ci. The table exists to answer "why did that purchase order
 * not land", and the join that answers it, mhd_po_seen.job_ref against
 * card_requests.job_ref, fails with error 1267, illegal mix of collations.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $row = $db->fetchOne(
        "SELECT TABLE_COLLATION c FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mhd_po_seen'");
    $current = (string)($row['c'] ?? '');
    if ($current === '') {
        echo "Migration 163: mhd_po_seen not present, skipping\n";
        exit(0);
    }
    if ($current === 'utf8mb4_unicode_ci') {
        echo "Migration 163: mhd_po_seen already utf8mb4_unicode_ci\n";
        exit(0);
    }
    $db->exec("ALTER TABLE `mhd_po_seen`
               CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Migration 163: mhd_po_seen converted from {$current} to utf8mb4_unicode_ci\n";
} catch (Exception $e) {
    echo "Migration 163 failed: " . $e->getMessage() . "\n";
    exit(1);
}
