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
 * The NFC price: /pricing, /nfc-business-card, the FAQ and llms.txt all publish
 * OMR 10.000, pinned by nfc_bilingual_seo_contract_test.php. BHD's print shop
 * pricing JSON carries an nfc tier of 25 per card. Nothing charges 25 today
 * because no order path can select nfc as a paper type, so the two numbers
 * never meet a customer. This test keeps that true: the moment nfc becomes
 * orderable, it has to agree with the published price.
 */
require_once dirname(__DIR__, 2) . '/includes/CardPaperTypes.php';

$root = dirname(__DIR__, 2);
$failures = 0;
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
    'nfc is not orderable, so the published 10.000 and the stored 25 never meet a customer',
    'if this is now sold, the two prices have to be reconciled first'
);

$enPricing = require $root . '/lang/en/pricing.php';
paperCheck(
    $enPricing['product_nfc_price'] === 'OMR 10.000',
    'the published NFC price is still the one the marketing pages carry',
    $enPricing['product_nfc_price'] ?? '(missing)'
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
