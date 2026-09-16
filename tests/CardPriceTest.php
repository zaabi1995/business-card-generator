<?php
/**
 * CardPrice: the portal may quote only the standard card, at the rate BHD
 * already bills MHD. Run: /www/server/php/83/bin/php tests/CardPriceTest.php
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardPrice.php';

$fails = 0;
function t(string $name, bool $ok): void {
    global $fails;
    echo ($ok ? "PASS  " : "FAIL  ") . $name . "\n";
    if (!$ok) { $fails++; }
}
$near = fn(float $a, float $b): bool => abs($a - $b) < 0.0005;

$q = CardPrice::quote(200);
t('200 net is 6.000',            $near($q['net'], 6.000));
t('200 vat is 0.300',            $near($q['vat'], 0.300));
t('200 gross is 6.300',          $near($q['gross'], 6.300));
t('describes the standard stock', $q['description'] === 'Business Card (Art 300 GSM, Matte)');

t('100 gross is 3.150',  $near(CardPrice::quote(100)['gross'], 3.150));
t('300 gross is 9.450',  $near(CardPrice::quote(300)['gross'], 9.450));
t('400 gross is 12.600', $near(CardPrice::quote(400)['gross'], 12.600));

t('four standard lots',        CardPrice::QUANTITIES === [100, 200, 300, 400]);
t('200 is the default',        CardPrice::DEFAULT_QTY === 200);
t('500 is not standard',       CardPrice::isStandardQuantity(500) === false);
t('1000 is not standard',      CardPrice::isStandardQuantity(1000) === false);
t('250 is not standard',       CardPrice::isStandardQuantity(250) === false);

$threw = false;
try { CardPrice::quote(250); } catch (InvalidArgumentException $e) { $threw = true; }
t('refuses an off-menu quantity', $threw);

// A division on its own negotiated rate still prices correctly.
t('honours a per-division rate', $near(CardPrice::quote(200, 0.050)['gross'], 10.500));

echo $fails === 0 ? "\nall passed\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
