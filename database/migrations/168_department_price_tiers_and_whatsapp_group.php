<?php
/**
 * Migration 168: a division can price by lot size and has its own WhatsApp group.
 *
 * - departments.card_price_tiers: JSON {"<lot>": <rate per card>}. Oman Housing
 *   Bank asked for a special price on 21 Sep 2026 and Ali agreed in the OHB Cards
 *   group: 100 pcs 4.000 (0.040), 200 pcs 6.000 (0.030). One flat rate cannot say
 *   that.
 * - departments.whatsapp_group: the client's WhatsApp group id. The division's
 *   job updates (quotation, production) are posted there. OHB = "OHB Cards".
 */
require_once __DIR__ . '/../../config.php';

const OHB_COMPANY_ID = 'a0b10000-0000-0000-0000-00000000b001';

try {
    $db = Database::getInstance();
    if (!$db->columnExists('departments', 'card_price_tiers')) {
        $db->exec("ALTER TABLE departments ADD COLUMN card_price_tiers VARCHAR(500) NULL DEFAULT NULL
                   COMMENT 'JSON lot => rate per card; overrides card_unit_price'");
        echo "Migration 168: added departments.card_price_tiers\n";
    }
    if (!$db->columnExists('departments', 'whatsapp_group')) {
        $db->exec("ALTER TABLE departments ADD COLUMN whatsapp_group VARCHAR(64) NULL DEFAULT NULL
                   COMMENT 'Client WhatsApp group id for job updates'");
        echo "Migration 168: added departments.whatsapp_group\n";
    }
    $n = $db->query(
        "UPDATE departments SET card_price_tiers = ?, card_unit_price = 0.030,
                whatsapp_group = '120363312994062754', updated_at = NOW()
          WHERE company_id = ? AND slug = 'ohb'",
        ['{"100":0.040,"200":0.030}', OHB_COMPANY_ID]
    )->rowCount();
    echo "Migration 168: OHB priced 100=0.040, 200+=0.030, group OHB Cards ({$n} row)\n";
} catch (Exception $e) {
    echo "Migration 168 failed: " . $e->getMessage() . "\n";
    exit(1);
}
