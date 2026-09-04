<?php
/**
 * The billing page showed a card-credit purchase block advertising 0.500 OMR
 * per card, immediately under "Free for every team, unlimited cards" and a
 * monthly limit of infinity. The block renders when $pricePerCard > 0, and the
 * default was 0.500 with no plans table for $currentPlan to override it from.
 * Estate-wide on 4 Sep 2026 the credit ledger held 0 rows and no company held a
 * credit, across 94 companies, while cards generated freely.
 *
 * The page already has the honest branch. This keeps the default on it.
 */
$root = dirname(__DIR__, 2);
$src  = file_get_contents($root . '/admin/billing.php');
$en   = require $root . '/lang/en/billing.php';

$failures = 0;
function billCheck(bool $c, string $label, string $detail = ''): void
{
    global $failures;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $label;
    if (!$c && $detail !== '') echo ' (' . $detail . ')';
    echo "\n";
    if (!$c) $failures++;
}

billCheck(
    preg_match('/\$pricePerCard = 0\.000;/', $src) === 1
        && !preg_match('/\$pricePerCard = 0\.500;/', $src),
    'the per-card price defaults to free, matching what generation costs'
);
billCheck(
    str_contains($src, "\$currentPlan['price_per_card'] ?? 0.000"),
    'a plan with no price set also means free, not 0.500'
);
billCheck(
    str_contains($src, 'if ($pricePerCard > 0):'),
    'a real priced plan can still show the purchase block'
);
billCheck(
    str_contains($src, "t('billing.card_included')"),
    'the free-and-unlimited branch is the one that renders by default'
);
billCheck(
    str_contains($en['card_included'], 'free and unlimited')
        && str_contains($en['card_credits_sub'], 'unlimited'),
    'the copy on that branch says free and unlimited'
);

echo $failures === 0 ? "\nALL PASS\n" : "\n{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
