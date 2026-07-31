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

myCardRenderCheck(
    'POST refetches and returns the same freshly rendered canonical card while preserving brand lock state',
    substr_count($myCard, 'SELECT * FROM employees WHERE id = :id AND company_id = :cid') >= 2
        && strpos($myCard, "['success' => true, 'brand_locked' => \$brandLocked]") === false
        && strpos($myCard, "\$response = ['success' => true, 'card' => \$card]") !== false
        && strpos($myCard, "\$response['brand_locked'] = \$brandLocked") !== false
);
myCardRenderCheck(
    'server card policy protects managed design fields',
    strpos($myCard, "require_once INCLUDES_DIR . '/CardPolicy.php'") !== false
        && strpos($myCard, "\$response['card_policy'] = \$cardPolicy") !== false
        && strpos($myCard, "'locked_fields'") !== false
        && strpos($myCard, "'card_template_id'") !== false
        && strpos($myCard, "'primary_color'") !== false
        && strpos($myCard, "'dark_mode'") !== false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
