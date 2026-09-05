<?php
/**
 * Paper and finish options, and the NFC price question the gauntlet was pointed
 * at on 4 Sep 2026.
 *
 * Three screens each carried their own paper list and they disagreed.
 * admin/order_print.php offered matte, glossy and silk to a company;
 * printshop/order-on-behalf.php offered uncoated, matte and silk for the same
 * order placed by the shop; printshop/client-pricing.php, where a shop sets its
 * rates, offered uncoated, matte and silk. A company could order glossy, which
 * no shop could price, and could not order uncoated, which every shop could.
 *
 * The NFC price: two numbers were in the estate. Every published surface said
 * OMR 10.000 while BHD's stored shop JSON carried an nfc tier of 25 per card.
 * Ali settled it at 10.000 on 5 Sep 2026, includes/CardCatalogPricing.php now
 * holds the only copy of that number, CardPrintPricing reads it instead of the
 * shop JSON, and migration 156 rewrote the stored 25. This test keeps `nfc` out
 * of the orderable list until someone decides to sell it, and checks that the
 * price it would be sold at comes from the catalogue.
 */
require_once dirname(__DIR__, 2) . '/includes/CardPaperTypes.php';

$root = dirname(__DIR__, 2);
$failures = 0;
function I18nRenderedNfcPrice(string $root): string
{
    require_once $root . '/includes/I18n.php';
    return I18n::t('pricing.product_nfc_price', [], 'en');
}
function paperCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

$screens = [
    'admin/order_print.php',
    'printshop/order-on-behalf.php',
    'printshop/client-pricing.php',
];
foreach ($screens as $rel) {
    $src = file_get_contents($root . '/' . $rel);
    paperCheck(
        str_contains($src, 'CardPaperTypes::orderableOptions()'),
        "{$rel} renders the shared paper list"
    );
}

// No screen may hardcode a paper option again.
$hardcoded = [];
foreach ($screens as $rel) {
    $src = file_get_contents($root . '/' . $rel);
    foreach (['matte', 'glossy', 'silk', 'uncoated', 'soft_touch', 'luxury_450', 'nfc'] as $key) {
        if (preg_match('/<option value="' . preg_quote($key, '/') . '"/', $src)) {
            $hardcoded[] = "{$rel}:{$key}";
        }
    }
}
paperCheck($hardcoded === [], 'no screen hardcodes a paper option', implode(', ', $hardcoded));

paperCheck(
    CardPaperTypes::isOrderable(CardPaperTypes::DEFAULT_TYPE),
    'the default paper type is one a customer can actually pick'
);

$notPriceable = array_diff(array_keys(CardPaperTypes::ORDERABLE), CardPaperTypes::PRICEABLE);
paperCheck(
    $notPriceable === [],
    'every orderable paper type is one a shop can hold a rate for',
    implode(', ', $notPriceable)
);

// The NFC guard. nfc stays out of the orderable list while the published price
// and BHD's shop tier disagree.
paperCheck(
    !CardPaperTypes::isOrderable('nfc'),
    'nfc stays out of the orderable list until someone decides to sell it'
);

require_once $root . '/includes/CardCatalogPricing.php';
require_once $root . '/includes/CardPrintPricing.php';
paperCheck(
    CardCatalogPricing::isCardifyPriced('nfc'),
    'nfc is priced by Cardify, so no shop can publish a different number for it'
);
// The stale 25, kept here as the exact shape that was stored, so the guard
// survives even if the row is later re-imported from a backup.
$stored25 = ['paper_type_pricing' => ['nfc' => ['quantity_tiers' => ['1' => ['per_card' => 25]]]]];
paperCheck(
    abs(CardPrintPricing::shopPricePerCard($stored25, 1, 'nfc') - CardCatalogPricing::amount('nfc')) < 0.0001,
    'if nfc ever becomes orderable it charges the catalogue price, not a stored one',
    (string) CardPrintPricing::shopPricePerCard($stored25, 1, 'nfc')
);
paperCheck(
    I18nRenderedNfcPrice($root) === 'OMR 10.000',
    'the published NFC price is the one the marketing pages render',
    I18nRenderedNfcPrice($root)
);

$emDash = "\xE2\x80\x94";
foreach (array_merge($screens, ['includes/CardPaperTypes.php']) as $rel) {
    paperCheck(
        !str_contains(file_get_contents($root . '/' . $rel), $emDash),
        "{$rel} contains no em dash"
    );
}

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
