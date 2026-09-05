<?php
/**
 * Migration 156: bring every stored NFC rate onto the canonical price.
 *
 * The gauntlet found two NFC prices in the estate. /pricing, /nfc-business-card,
 * the FAQ, the home page JSON-LD and llms.txt all published OMR 10.000 per card.
 * BHD's print_shops.pricing JSON carried paper_type_pricing.nfc with a single
 * tier of 25 per card. Nothing charged 25, because no order path offers `nfc`
 * as a paper type, so the row sat there as a trap rather than as a bug.
 *
 * Ali set the canonical selling price at OMR 10.000 per NFC card on 5 Sep 2026.
 * includes/CardCatalogPricing.php now holds that number, CardPrintPricing reads
 * it instead of the shop JSON for any Cardify-priced type, and this migration
 * rewrites the stored numbers so nothing in the database disagrees either.
 *
 * Idempotent: it writes only rows whose stored NFC tiers differ from canonical.
 * Nothing else in the pricing JSON is touched.
 */
require_once __DIR__ . '/../../config.php';
// INCLUDES_DIR, not a relative hop: config.php defines it and the runner may
// be invoked from anywhere.
require_once INCLUDES_DIR . '/CardCatalogPricing.php';

try {
    $pdo = Database::getInstance()->getConnection();

    $canonical = CardCatalogPricing::amount('nfc');
    $rows = $pdo->query("SELECT id, name, pricing FROM print_shops")->fetchAll(PDO::FETCH_ASSOC);
    $changed = 0;

    foreach ($rows as $row) {
        $pricing = json_decode((string) $row['pricing'], true);
        if (!is_array($pricing)) continue;
        if (!isset($pricing['paper_type_pricing']['nfc'])) continue;

        $storedNfc = json_encode($pricing['paper_type_pricing']['nfc']);
        $pricing['paper_type_pricing']['nfc'] = CardCatalogPricing::shopTier('nfc');
        $canonicalNfc = json_encode($pricing['paper_type_pricing']['nfc']);
        if ($storedNfc === $canonicalNfc) {
            echo "[156] Shop {$row['id']} ({$row['name']}) already canonical\n";
            continue;
        }

        $stmt = $pdo->prepare("UPDATE print_shops SET pricing = :p WHERE id = :id");
        $stmt->execute([
            ':p'  => json_encode($pricing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':id' => $row['id'],
        ]);
        $changed++;
        echo "[156] Shop {$row['id']} ({$row['name']}) NFC {$storedNfc} -> {$canonicalNfc}\n";
    }

    echo "[156] Canonical NFC price " . number_format($canonical, 3) . " OMR, {$changed} shop(s) rewritten\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[156] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
