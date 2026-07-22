<?php
// database/migrations/139_card_hero_actions.php
// Optional per-tenant control of WHICH top action buttons show on the digital
// card (Call / WhatsApp / Email). Comma list from {call,whatsapp,email}.
// Empty (default) = show all three = current behaviour, unchanged for every
// existing tenant. e.g. 'whatsapp' = only the WhatsApp button (full-width).
require_once __DIR__ . '/../../config.php';
try {
    $db = Database::getInstance();
    if (!$db->columnExists('companies', 'ecard_hero_actions')) {
        $db->exec("ALTER TABLE companies ADD COLUMN ecard_hero_actions VARCHAR(64) NOT NULL DEFAULT ''");
        echo "Migration 139: companies.ecard_hero_actions added\n";
    } else {
        echo "Migration 139: already exists, skipped\n";
    }
} catch (Exception $e) {
    echo "Migration 139 failed: " . $e->getMessage() . "\n";
    exit(1);
}
