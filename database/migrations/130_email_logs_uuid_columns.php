<?php
// Widen email_logs.company_id + employee_id from INT to VARCHAR(36).
// Every tenant/employee id in Cardify is a UUID/slug string, but these
// two columns were created as INT(11). Passing a UUID truncated to 0
// (MySQL "Data truncated for column 'company_id'") and the whole
// email log insert failed, so welcome/OTP/order emails sent but were
// never recorded. The columns are only indexed + counted, never joined
// as an int, so widening is safe.
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    $db->exec("ALTER TABLE email_logs MODIFY company_id VARCHAR(36) NULL");
    $db->exec("ALTER TABLE email_logs MODIFY employee_id VARCHAR(36) NULL");
    echo "Migration 130: email_logs.company_id + employee_id widened to VARCHAR(36)\n";
} catch (Exception $e) {
    echo "Migration 130 failed: " . $e->getMessage() . "\n";
    exit(1);
}
