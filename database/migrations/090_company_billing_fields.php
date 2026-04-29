<?php
/**
 * Migration 090: company billing fields (Cat S action 446).
 *
 * Companies in Oman need a Commercial Registration (CR) number and,
 * if VAT-registered, an Oman Tax Authority number on every tax
 * invoice. Adds both plus a multi-line billing address so the receipt
 * shows the legal "bill to" block the government requires.
 *
 * All nullable so legacy companies keep working. Admin UI at
 * /admin/billing-info writes these columns.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php'; // addColumnIfMissing()

try {
    $db = Database::getInstance();

    $exists = $db->fetchOne(
        "SELECT COUNT(*) c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'companies'"
    );
    if ((int) ($exists['c'] ?? 0) === 0) {
        echo "Migration 090: companies table missing, skipping\n";
        exit(0);
    }

    addColumnIfMissing($db, 'companies', 'cr_number VARCHAR(40) NULL');
    addColumnIfMissing($db, 'companies', 'tax_id VARCHAR(40) NULL');
    addColumnIfMissing($db, 'companies', 'vat_registered TINYINT(1) NOT NULL DEFAULT 0');
    addColumnIfMissing($db, 'companies', 'billing_address TEXT NULL');
    addColumnIfMissing($db, 'companies', 'billing_city VARCHAR(80) NULL');
    addColumnIfMissing($db, 'companies', 'billing_postcode VARCHAR(20) NULL');
    addColumnIfMissing($db, 'companies', 'billing_country VARCHAR(2) NULL DEFAULT \'OM\'');

    echo "Migration 090: company billing fields ready\n";
} catch (Exception $e) {
    echo "Migration 090 failed: " . $e->getMessage() . "\n";
    exit(1);
}
