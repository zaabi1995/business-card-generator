<?php
/**
 * Migration 101: opt-out toggle for the "employee self-edited their card"
 * email summary sent to the company admin. Default ON so existing
 * tenants get the audit trail automatically.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $has = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'company_settings'
           AND column_name = 'notify_on_employee_edit'"
    )->fetchColumn();

    if ((int) $has === 0) {
        $pdo->exec(
            "ALTER TABLE company_settings
             ADD COLUMN notify_on_employee_edit TINYINT(1) NOT NULL DEFAULT 1 AFTER whatsapp_token"
        );
        echo "[101] Added company_settings.notify_on_employee_edit\n";
    } else {
        echo "[101] company_settings.notify_on_employee_edit already exists, skipping\n";
    }
    echo "[101] Migration complete\n";
} catch (Exception $e) {
    echo "[101] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
