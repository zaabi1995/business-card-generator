<?php
/**
 * Per-tenant gate: keep a portal-submitted card off the public web until an
 * admin approves it.
 *
 * The portal creates the employee row on submit so the print PDF can be
 * rendered and emailed, and digital_card.php also resolves a still-pending
 * card_request. Both are deliberate for self-serve tenants, where the card
 * going live instantly is the product. For a tenant whose portal is an
 * internal HR queue (MHD), it means anyone with a company address can publish
 * a company-branded public page before anyone reviews it, while the portal
 * tells them "nothing prints until HR approves".
 *
 * Defaults to 0 so every existing tenant keeps today's behaviour.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    if (!$db->columnExists('companies', 'card_requires_approval')) {
        $db->exec("ALTER TABLE companies
                   ADD COLUMN card_requires_approval TINYINT(1) NOT NULL DEFAULT 0");
        echo "Migration 158: companies.card_requires_approval added\n";
    } else {
        echo "Migration 158: companies.card_requires_approval already present\n";
    }
} catch (Exception $e) {
    echo "Migration 158 failed: " . $e->getMessage() . "\n";
    exit(1);
}
