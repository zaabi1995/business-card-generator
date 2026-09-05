<?php
/**
 * The published catalogue price, pinned to one source.
 *
 * The gauntlet was pointed at a single question: is an NFC card 10 or 25? Both
 * numbers were in the estate. Every published surface said OMR 10.000. BHD's
 * print_shops.pricing JSON carried an nfc tier of 25 per card. Nothing charged
 * 25 only because no order path could select `nfc` as a paper type, so it was
 * a trap waiting for the day someone made it orderable.
 *
 * Ali set the canonical price at OMR 10.000 per NFC card on 5 Sep 2026.
 * includes/CardCatalogPricing.php is now the only place any catalogue amount is
 * written. This test fails if a number reappears anywhere else: in the lang
 * copy, in the JSON-LD, in llms.txt, or in a shop's stored rate.
 */
require_once dirname(__DIR__, 2) . '/includes/CardCatalogPricing.php';
require_once dirname(__DIR__, 2) . '/includes/CardPrintPricing.php';

$root = dirname(__DIR__, 2);
$failures = 0;
function ccp(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

// 1. the canonical numbers themselves
ccp(CardCatalogPricing::decimal('nfc') === '10.000', 'the canonical NFC price is OMR 10.000',
    CardCatalogPricing::decimal('nfc'));
ccp(CardCatalogPricing::decimal('standard') === '5.000', 'standard is 5.000 per 100');
ccp(CardCatalogPricing::decimal('premium') === '6.000', 'premium is 6.000 per 100');
ccp(CardCatalogPricing::decimal('luxury') === '15.000', 'luxury is 15.000 per 100');
foreach (array_keys(CardCatalogPricing::AMOUNTS) as $k) {
    ccp(preg_match('/^\d+\.\d{3}$/', CardCatalogPricing::decimal($k)) === 1,
        "{$k} renders with the three decimals OMR takes", CardCatalogPricing::decimal($k));
}

// 2. the resolver ignores a stale stored rate for a Cardify-priced type.
//    This is the one that would have turned the trap into a charge.
$stale = ['paper_type_pricing' => ['nfc' => ['quantity_tiers' => ['1' => ['price' => 25, 'per_card' => 25]]]]];
ccp(
    abs(CardPrintPricing::shopPricePerCard($stale, 1, 'nfc') - 10.0) < 0.0001,
    'a shop holding the old 25 still resolves to the canonical 10.000',
    (string) CardPrintPricing::shopPricePerCard($stale, 1, 'nfc')
);
$shopOwn = ['paper_type_pricing' => ['matte' => ['quantity_tiers' => ['100' => ['per_card' => 0.045]]]]];
ccp(
    abs(CardPrintPricing::shopPricePerCard($shopOwn, 100, 'matte') - 0.045) < 0.0001,
    'a shop still sets its own price for the papers it actually prints'
);

// 3. no lang file carries a catalogue amount. Copy holds a placeholder.
$langHits = [];
foreach (glob($root . '/lang/{en,ar}/*.php', GLOB_BRACE) as $file) {
    $src = file_get_contents($file);
    foreach (CardCatalogPricing::AMOUNTS as $key => $amount) {
        $num = number_format($amount, 3, '.', '');
        if (str_contains($src, $num)) {
            $langHits[] = basename(dirname($file)) . '/' . basename($file) . ':' . $num;
        }
    }
}
ccp($langHits === [], 'no lang file writes a catalogue amount of its own',
    implode(', ', array_slice($langHits, 0, 6)));

// 4. and the placeholders actually resolve, in both locales.
require_once $root . '/includes/I18n.php';
foreach (['en', 'ar'] as $locale) {
    foreach (['pricing.product_nfc_price', 'faq.pr4_a', 'nfc.row_5_nfc', 'landing.hero_price_tag'] as $key) {
        $out = I18n::t($key, [], $locale);
        ccp(!str_contains($out, ':nfc_price') && !str_contains($out, ':standard_price'),
            "{$locale} {$key} has its price substituted", $out);
    }
    ccp(str_contains(I18n::t('pricing.product_nfc_price', [], $locale), '10.000'),
        "{$locale} renders the canonical NFC price");
}

// 5. the JSON-LD offers on the home page read the class, not a literal.
$index = file_get_contents($root . '/index.php');
$offerBlock = substr($index, strpos($index, '"name": "Standard Printed Cards"'));
$offerBlock = substr($offerBlock, 0, strpos($offerBlock, '"name": "Cardify Business Card Platform"') ?: 8000);
$literal = [];
foreach (CardCatalogPricing::AMOUNTS as $key => $amount) {
    if (str_contains($offerBlock, '"' . number_format($amount, 3, '.', '') . '"')) $literal[] = $key;
}
ccp($literal === [], 'the home page Offer blocks carry no literal amount', implode(', ', $literal));
ccp(substr_count($index, "CardCatalogPricing::decimal(") >= 12,
    'each Offer takes all three of its amounts from the class',
    (string) substr_count($index, "CardCatalogPricing::decimal("));

// 6. /pricing publishes the same numbers through Seo::product
$pricingPage = file_get_contents($root . '/pricing.php');
ccp(
    !preg_match("/Seo::product\('(standard|premium|luxury|nfc)'[^\n]*'\d+'/", $pricingPage),
    'no Seo::product call still passes a hardcoded amount'
);

// 7. llms.txt is a static file the model crawlers read. It cannot include PHP,
//    so it is checked instead of generated.
$llms = file_get_contents($root . '/llms.txt');
ccp(
    str_contains($llms, 'NFC cards: OMR ' . CardCatalogPricing::decimal('nfc') . ' per card.'),
    'llms.txt quotes the canonical NFC price'
);
$llmsStale = [];
foreach (['25.000', 'OMR 25'] as $bad) {
    if (str_contains($llms, $bad)) $llmsStale[] = $bad;
}
ccp($llmsStale === [], 'llms.txt carries no stale NFC amount', implode(', ', $llmsStale));

// 8. the shop tier helper produces exactly what migration 156 writes
$tier = CardCatalogPricing::shopTier('nfc');
ccp(
    ($tier['quantity_tiers']['1']['per_card'] ?? null) === 10.0
    && ($tier['quantity_tiers']['1']['price'] ?? null) === 10.0,
    'shopTier() builds the stored shape from the canonical amount'
);
ccp(is_file($root . '/database/migrations/156_nfc_price_canonical.php'),
    'the migration that rewrites stored NFC rates is in the tree');

$emDash = "\xE2\x80\x94";
foreach (['includes/CardCatalogPricing.php', 'database/migrations/156_nfc_price_canonical.php'] as $f) {
    ccp(!str_contains(file_get_contents($root . '/' . $f), $emDash), "{$f} contains no em dash");
}

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
