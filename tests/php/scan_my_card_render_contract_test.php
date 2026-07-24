<?php
$root = dirname(__DIR__, 2);
$failures = 0;

function myCardRenderCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$myCard = (string) file_get_contents($root . '/api/scan/my-card.php');

myCardRenderCheck(
    'my-card render comes from the authenticated employee canonical renderer',
    strpos($myCard, "ScanAuth::requireEmployee()") !== false
        && strpos($myCard, "CardRenderer::forEmployee((string) \$employeeId)") !== false
        && strpos($myCard, 'Phone::normalize') === false
        && strpos($myCard, 'LOWER(TRIM(e.phone))') === false
);

myCardRenderCheck(
    'my-card exposes the complete optional render contract',
    strpos($myCard, "\$card['render']") !== false
        && strpos($myCard, "'front_url'") !== false
        && strpos($myCard, "'back_url'") !== false
        && strpos($myCard, "'aspect_ratio'") !== false
        && strpos($myCard, "'signature'") !== false
);

myCardRenderCheck(
    'my-card render URLs are absolute HTTPS URLs rooted at Cardify',
    strpos($myCard, "'https://' . cardifyApexHost()") !== false
        && strpos($myCard, 'BASE_DIR') !== false
        && strpos($myCard, "\$_SERVER['HTTP_HOST']") === false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
