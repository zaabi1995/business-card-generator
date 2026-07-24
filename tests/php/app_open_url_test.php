<?php
require_once __DIR__ . '/../../includes/UrlSafety.php';

$cases = [
    ['https://cardify.om/company/card/person', 'https://cardify.om/company/card/person'],
    ['https://MHD.CARDIFY.OM/profile?lang=ar#section', 'https://mhd.cardify.om/profile?lang=ar'],
    ['https://evil.example/card', null],
    ['https://cardify.om.evil.example/card', null],
    ['https://cardify.om:443/card', null],
    ['https://user@cardify.om/card', null],
    ['http://cardify.om/card', null],
    ['not a url', null],
];

foreach ($cases as [$input, $expected]) {
    $actual = normalizeCardifyUrl($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL normalizeCardifyUrl input=" . json_encode($input) . PHP_EOL);
        exit(1);
    }
}

echo "PASS Cardify app-open URL policy" . PHP_EOL;
