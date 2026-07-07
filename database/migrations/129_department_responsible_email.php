<?php
// database/migrations/129_department_responsible_email.php
// Adds routing columns to departments for the MHD combined portal:
// responsible_email (CC target on Send), cc_emails (extra CCs), include_qr_default.
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    $stmts = [
        "ALTER TABLE departments ADD COLUMN responsible_email VARCHAR(255) NULL",
        "ALTER TABLE departments ADD COLUMN cc_emails TEXT NULL",
        "ALTER TABLE departments ADD COLUMN include_qr_default TINYINT(1) NOT NULL DEFAULT 1",
    ];
    foreach ($stmts as $sql) {
        try {
            $db->exec($sql);
            echo "ok: $sql\n";
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'Duplicate column') !== false) {
                echo "skip (exists): $sql\n";
            } else {
                throw $e;
            }
        }
    }
    echo "Migration 129: department routing columns done\n";
} catch (Exception $e) {
    echo "Migration 129 failed: " . $e->getMessage() . "\n";
    exit(1);
}
