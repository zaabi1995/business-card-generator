<?php
// tests/php/scan_shadow_test.php
require_once __DIR__ . '/../../includes/ShadowProfileService.php';

function check($label, $actual, $expected) {
    $ok = $actual === $expected;
    echo ($ok ? "PASS" : "FAIL") . " $label\n";
    if (!$ok) { var_dump($actual); exit(1); }
}

check('local omani mobile', ShadowProfileService::normalizePhone('9770 7134'), '+96897707134');
check('already e164', ShadowProfileService::normalizePhone('+968 9770 7134'), '+96897707134');
check('00 prefix', ShadowProfileService::normalizePhone('0096897707134'), '+96897707134');
check('uae number kept', ShadowProfileService::normalizePhone('+971501234567'), '+971501234567');
check('junk is null', ShadowProfileService::normalizePhone('fax'), null);
check('empty is null', ShadowProfileService::normalizePhone(''), null);
echo "ALL PASS\n";
