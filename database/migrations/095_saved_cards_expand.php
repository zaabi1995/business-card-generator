<?php
/**
 * Migration 095: expand saved_cards to match BHD-ERP field set.
 *
 * Adds the extra card-detail columns Paymob returns in webhook.obj.data.*
 * (masked PAN, card_type credit/debit, payment_method card/omannet/wallet,
 * BIN, issuer bank, issuing country, integration_id), plus an updated_at
 * timestamp. Also switches the upsert key from paymob_token to
 * (company_id, last_four) so re-saving the same card updates the existing
 * row instead of inserting a duplicate ignored row.
 *
 * PCI scope stays at Paymob, we only store last4, masked (e.g. 512345xxxxxx6789),
 * brand, and non-sensitive routing metadata.
 */
require_once __DIR__ . '/../../config.php';

$db = Database::getInstance();

$columnAdds = [
    'masked_number'   => "ADD COLUMN masked_number VARCHAR(32) NULL AFTER last_four",
    'card_type'       => "ADD COLUMN card_type VARCHAR(16) NULL AFTER masked_number",
    'payment_method'  => "ADD COLUMN payment_method VARCHAR(32) NULL AFTER card_type",
    'bin'             => "ADD COLUMN bin VARCHAR(8) NULL AFTER expiry_year",
    'issuer'          => "ADD COLUMN issuer VARCHAR(128) NULL AFTER bin",
    'country'         => "ADD COLUMN country VARCHAR(8) NULL AFTER issuer",
    'integration_id'  => "ADD COLUMN integration_id INT NULL AFTER country",
    'updated_at'      => "ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
];

try {
    // Discover existing columns so we only ADD what's missing (idempotent reruns).
    $existing = [];
    $rows = $db->fetchAll("SHOW COLUMNS FROM saved_cards");
    foreach ($rows as $r) {
        $existing[strtolower($r['Field'])] = true;
    }

    $alters = [];
    foreach ($columnAdds as $col => $clause) {
        if (!isset($existing[strtolower($col)])) {
            $alters[] = $clause;
        }
    }

    if (!empty($alters)) {
        $sql = "ALTER TABLE saved_cards " . implode(', ', $alters);
        $db->exec($sql);
        echo "Migration 095: added " . count($alters) . " column(s) to saved_cards\n";
    } else {
        echo "Migration 095: saved_cards already has all expected columns\n";
    }

    // Before adding the new unique key, drop rows that would collide on
    // (company_id, last_four). Keep the most recently used row per pair.
    // Rows with NULL last_four are left alone (unique keys ignore NULLs in
    // MySQL anyway, so they won't conflict with the new constraint).
    $db->exec(<<<'SQL'
        DELETE sc FROM saved_cards sc
        INNER JOIN (
            SELECT company_id, last_four,
                   MAX(COALESCE(last_used_at, created_at)) AS keep_ts
            FROM saved_cards
            WHERE last_four IS NOT NULL
            GROUP BY company_id, last_four
            HAVING COUNT(*) > 1
        ) dup
        ON sc.company_id = dup.company_id
        AND sc.last_four = dup.last_four
        AND COALESCE(sc.last_used_at, sc.created_at) < dup.keep_ts
SQL);

    // Check existing indexes so we only add uk_company_last4 if missing.
    $idxRows = $db->fetchAll("SHOW INDEX FROM saved_cards");
    $hasCompanyLast4 = false;
    foreach ($idxRows as $ir) {
        if (strtolower($ir['Key_name']) === 'uk_company_last4') {
            $hasCompanyLast4 = true;
            break;
        }
    }

    if (!$hasCompanyLast4) {
        // IGNORE drops any remaining duplicates as it builds the unique key,
        // belt-and-suspenders on top of the DELETE above.
        $db->exec("ALTER IGNORE TABLE saved_cards ADD UNIQUE KEY uk_company_last4 (company_id, last_four)");
        echo "Migration 095: added UNIQUE KEY uk_company_last4\n";
    } else {
        echo "Migration 095: uk_company_last4 already present\n";
    }

    echo "Migration 095: saved_cards expansion complete\n";
} catch (Exception $e) {
    echo "Migration 095 failed: " . $e->getMessage() . "\n";
    exit(1);
}
