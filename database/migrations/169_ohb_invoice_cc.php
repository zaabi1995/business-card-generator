<?php
/**
 * Migration 169: OHB copies invoices@ohb.co.om on its card emails.
 *
 * OHB, in the OHB Cards WhatsApp group on 24 Sep 2026: "Please send invoices to
 * this email address. invoices@ohb.co.om". CardJobMailer::recipients() now reads
 * departments.cc_emails, so every OHB card email (quotation, documents with the
 * invoice) copies it.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $n = $db->query(
        "UPDATE departments SET cc_emails = 'invoices@ohb.co.om', updated_at = NOW()
          WHERE company_id = 'a0b10000-0000-0000-0000-00000000b001' AND slug = 'ohb'
            AND (cc_emails IS NULL OR cc_emails = '')"
    )->rowCount();
    echo "Migration 169: OHB copies invoices@ohb.co.om ({$n} row)\n";
} catch (Exception $e) {
    echo "Migration 169 failed: " . $e->getMessage() . "\n";
    exit(1);
}
