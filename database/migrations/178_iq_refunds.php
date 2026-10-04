<?php
/**
 * Migration 178: IQ payments can be refunded (5 Oct 2026). A Paymob refund or
 * void now takes back what the payment granted; the status records it.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec("ALTER TABLE iq_payments MODIFY status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending'");
    echo "Migration 178: iq_payments.status can be refunded\n";
} catch (Exception $e) {
    echo "Migration 178 failed: " . $e->getMessage() . "\n";
    exit(1);
}
