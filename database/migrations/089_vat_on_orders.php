<?php
/**
 * Migration 089: Oman VAT on print orders (Cat S action 445).
 *
 * Adds tax_rate / tax_amount / subtotal_excl_vat so the invoice can
 * render a 5% VAT line. Nullable so legacy rows stay intact; the
 * display layer treats NULL as "VAT breakdown not captured for this
 * order" and hides the row cleanly.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/080_template_foundation.php'; // addColumnIfMissing()

try {
    $db = Database::getInstance();

    $exists = $db->fetchOne(
        "SELECT COUNT(*) c FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'print_orders'"
    );
    if ((int) ($exists['c'] ?? 0) === 0) {
        echo "Migration 089: print_orders not present, skipping\n";
        exit(0);
    }

    addColumnIfMissing($db, 'print_orders', 'tax_rate DECIMAL(5,4) NULL');
    addColumnIfMissing($db, 'print_orders', 'tax_amount DECIMAL(10,3) NULL');
    addColumnIfMissing($db, 'print_orders', 'subtotal_excl_vat DECIMAL(10,3) NULL');

    echo "Migration 089: VAT columns ready on print_orders\n";
} catch (Exception $e) {
    echo "Migration 089 failed: " . $e->getMessage() . "\n";
    exit(1);
}
