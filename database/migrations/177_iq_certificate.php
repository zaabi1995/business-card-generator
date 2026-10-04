<?php
/**
 * Migration 177: the verified IQ certificate (OMR 4.900, Ali, 4 Oct 2026).
 * An attempt gets a certificate number, the name printed on it and the issue date once paid.
 * iq_payments gains the 'certificate' product and a meta column for the name the buyer confirmed.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $cols = array_column($db->fetchAll('SHOW COLUMNS FROM iq_attempts'), 'Field');
    if (!in_array('cert_no', $cols, true)) {
        $db->exec('ALTER TABLE iq_attempts ADD COLUMN cert_no VARCHAR(20) NULL, ADD COLUMN cert_name VARCHAR(80) NULL,
                   ADD COLUMN cert_issued_at DATETIME NULL, ADD UNIQUE KEY uq_iq_attempts_cert (cert_no)');
    }
    $db->exec("ALTER TABLE iq_payments MODIFY product ENUM('report','pro_month','certificate') NOT NULL");
    $pcols = array_column($db->fetchAll('SHOW COLUMNS FROM iq_payments'), 'Field');
    if (!in_array('meta', $pcols, true)) {
        $db->exec('ALTER TABLE iq_payments ADD COLUMN meta JSON NULL');
    }
    $db->exec("INSERT IGNORE INTO iq_settings (`key`, `value`) VALUES ('price_certificate', '4.900')");
    echo "Migration 177: certificate ready\n";
} catch (Exception $e) {
    echo "Migration 177 failed: " . $e->getMessage() . "\n";
    exit(1);
}
