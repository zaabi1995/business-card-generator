<?php
/**
 * Migration 165: a division can accept a different email domain from its company,
 * and a request keeps the employee record it overwrote.
 *
 * The portal only accepted companies.email_domain. MHD is mhd.co.om, but MHD
 * Logistics is a separate company on mhdlogistics.com, so no Logistics employee
 * could submit a card: "Only @mhd.co.om email addresses are allowed."
 * departments.email_domain, when set, is the domain that division accepts.
 */
require_once __DIR__ . '/../../config.php';

const MHD_COMPANY_ID = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';

try {
    $db = Database::getInstance();
    if ($db->columnExists('departments', 'email_domain')) {
        echo "Migration 165: departments.email_domain already present\n";
    } else {
        $db->exec("ALTER TABLE departments
                   ADD COLUMN email_domain VARCHAR(190) NULL DEFAULT NULL
                   COMMENT 'Email domain this division accepts; NULL = the company domain'");
        echo "Migration 165: added departments.email_domain\n";
    }
    // What an existing employee's card said before a request overwrote it, so a
    // rejected request can put it back instead of leaving the card changed and
    // offline.
    if (!$db->columnExists('card_requests', 'employee_snapshot')) {
        $db->exec("ALTER TABLE card_requests ADD COLUMN employee_snapshot LONGTEXT NULL DEFAULT NULL");
        echo "Migration 165: added card_requests.employee_snapshot\n";
    }
    $n = $db->query(
        "UPDATE departments SET email_domain = 'mhdlogistics.com', updated_at = NOW()
          WHERE company_id = ? AND slug = 'logistics'
            AND (email_domain IS NULL OR email_domain = '')",
        [MHD_COMPANY_ID]
    )->rowCount();
    echo "Migration 165: MHD Logistics set to mhdlogistics.com ({$n} row)\n";
} catch (Exception $e) {
    echo "Migration 165 failed: " . $e->getMessage() . "\n";
    exit(1);
}
