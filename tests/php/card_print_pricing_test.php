<?php
/**
 * The shared print-price ladder.
 *
 * Two defects on 4 Sep 2026, both in a money path:
 *
 * 1. pricePerCard() and lineTotal() fatalled on every call. They did
 *    reset(self::TIERS), and reset() takes its argument by reference, which a
 *    class constant cannot be. Confirmed on production PHP 8.3.25: "reset():
 *    Argument #1 ($array) could not be passed by reference". Only tiersForJs()
 *    worked, which is why the one caller never noticed.
 *
 * 2. admin/order_print.php quoted two different prices for one order on one
 *    screen. A shop with no tier pricing fell back to 0.10 in the PHP shop card
 *    and to 0.06 in the JavaScript review panel, so a 100-card order read
 *    "0.100/card" beside "100 cards x 0.060 = 6.000". Two hardcoded guesses at
 *    the same missing number. Both resolve through this class now.
 */
require_once dirname(__DIR__, 2) . '/includes/CardPrintPricing.php';

$root = dirname(__DIR__, 2);
$order = file_get_contents($root . '/admin/order_print.php');

$failures = 0;
function priceCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

// 1. the methods run at all
$ran = true;
try {
    CardPrintPricing::pricePerCard(100);
    CardPrintPricing::lineTotal(100);
} catch (Throwable $e) {
    $ran = false;
    $err = $e->getMessage();
}
priceCheck($ran, 'pricePerCard and lineTotal do not throw', $ran ? '' : ($err ?? ''));

// 2. the ladder resolves the way the tier table reads
$expected = [
    10   => 0.120,  // below the minimum, first tier applies
    50   => 0.120,
    99   => 0.120,
    100  => 0.100,
    249  => 0.100,
    250  => 0.090,
    499  => 0.090,
    500  => 0.075,
    999  => 0.075,
    1000 => 0.060,
    5000 => 0.060,
];
$wrong = [];
foreach ($expected as $qty => $want) {
    $got = CardPrintPricing::pricePerCard($qty);
    if (abs($got - $want) > 0.0001) $wrong[] = "{$qty}: got {$got}, want {$want}";
}
priceCheck($wrong === [], 'every quantity lands on the right tier', implode(' | ', $wrong));

// 3. line totals are the per-card price times the quantity, to 3dp
$totalWrong = [];
foreach ([50, 100, 250, 1000] as $qty) {
    $want = round($qty * CardPrintPricing::pricePerCard($qty), 3);
    $got  = CardPrintPricing::lineTotal($qty);
    if (abs($got - $want) > 0.0001) $totalWrong[] = "{$qty}: got {$got}, want {$want}";
}
priceCheck($totalWrong === [], 'line totals are quantity times the tier price, to 3 decimals', implode(' | ', $totalWrong));

// 4. the ladder never goes up as quantity rises
$prev = null; $monotonic = true;
foreach ([50, 100, 250, 500, 1000, 5000] as $qty) {
    $v = CardPrintPricing::pricePerCard($qty);
    if ($prev !== null && $v > $prev) $monotonic = false;
    $prev = $v;
}
priceCheck($monotonic, 'a bigger order never costs more per card');

// 5. the order page resolves an unpriced shop through this class, twice
priceCheck(
    str_contains($order, 'CardPrintPricing::shopPricePerCard(')
        && str_contains($order, 'CardPaperTypes::DEFAULT_TYPE'),
    'the shop card prices through the shared resolver, not a literal 0.10'
);
priceCheck(
    str_contains($order, 'perCardFromTiers(this.quantity)')
        && str_contains($order, 'const CARD_PRINT_TIERS = <?= json_encode(CardPrintPricing::tiersForJs()) ?>;'),
    'the review panel falls back to the same ladder, not to 0.06'
);
priceCheck(
    !preg_match('/per_card[\'"]?\s*\|\|\s*0\.06/', $order)
        && !str_contains($order, "\$pricing['per_card'] ?? 0.10"),
    'neither hardcoded fallback survives'
);
priceCheck(
    str_contains($order, '$defaultOrderQty = (int) ($currentCompany[\'default_order_qty\'] ?? 100);')
        && str_contains($order, 'quantity: <?php echo (int) $defaultOrderQty; ?>,'),
    'the page has one default quantity, shared by both panels'
);


// 6. the shop chooser quotes the shop's own ladder, not a generic fallback
$bhdShape = ['quantity_tiers' => [
    '100'  => ['price' => 5,  'per_card' => 0.05],
    '200'  => ['price' => 9,  'per_card' => 0.045],
    '500'  => ['price' => 15, 'per_card' => 0.03],
    '1000' => ['price' => 20, 'per_card' => 0.02],
]];
$flatShape = ['per_card' => 0.1];
$perPaper  = ['paper_type_pricing' => ['silk' => ['quantity_tiers' => [
    '100' => ['price' => 6, 'per_card' => 0.06],
    '500' => ['price' => 18, 'per_card' => 0.036],
]]]];

$shopWrong = [];
foreach ([[$bhdShape, 200, 'matte', 0.045], [$bhdShape, 1000, 'matte', 0.02],
          [$bhdShape, 50, 'matte', 0.05], [$flatShape, 999, 'matte', 0.1],
          [$perPaper, 500, 'silk', 0.036], [[], 250, 'matte', 0.09]] as [$shape, $qty, $paper, $want]) {
    $got = CardPrintPricing::shopPricePerCard($shape, $qty, $paper);
    if (abs($got - $want) > 0.0001) $shopWrong[] = "{$qty}/{$paper}: got {$got}, want {$want}";
}
priceCheck($shopWrong === [], 'shopPricePerCard reads every shape a shop stores its rates in', implode(' | ', $shopWrong));

priceCheck(
    str_contains($order, 'CardPrintPricing::shopPricePerCard('),
    'the shop chooser resolves the shop\'s own price, not a generic fallback'
);
priceCheck(
    !str_contains($order, "\$pricing['per_card']\n"),
    'the chooser no longer reads only the scalar per_card shape'
);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
