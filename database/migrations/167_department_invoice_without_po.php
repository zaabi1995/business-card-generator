<?php
/**
 * Migration 167: a division can be invoiced without waiting for a purchase order.
 *
 * MHD sends a PO for every card, so the MHD flow waits at "quoted" until one
 * arrives. Oman Housing Bank never sends a PO: BHD invoices it on approval. With
 * departments.invoice_without_po = 1 the job goes straight from the quotation to
 * the invoice, sales order, delivery note and production, with no PO step.
 */
require_once __DIR__ . '/../../config.php';

const OHB_COMPANY_ID = 'a0b10000-0000-0000-0000-00000000b001';

try {
    $db = Database::getInstance();
    if ($db->columnExists('departments', 'invoice_without_po')) {
        echo "Migration 167: departments.invoice_without_po already present\n";
    } else {
        $db->exec("ALTER TABLE departments
                   ADD COLUMN invoice_without_po TINYINT(1) NOT NULL DEFAULT 0
                   COMMENT '1 = invoice on approval, no purchase order step'");
        echo "Migration 167: added departments.invoice_without_po\n";
    }
    $n = $db->query(
        "UPDATE departments SET invoice_without_po = 1, updated_at = NOW()
          WHERE company_id = ? AND slug = 'ohb'",
        [OHB_COMPANY_ID]
    )->rowCount();
    echo "Migration 167: Oman Housing Bank invoices without a PO ({$n} row)\n";
} catch (Exception $e) {
    echo "Migration 167 failed: " . $e->getMessage() . "\n";
    exit(1);
}
