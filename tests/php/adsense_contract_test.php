<?php
require_once __DIR__ . '/../../includes/AdSense.php';
require_once __DIR__ . '/../../includes/SecurityHeaders.php';
function currentDir(): string { return $GLOBALS['testDir'] ?? 'ltr'; }
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$_SERVER['HTTP_HOST'] = 'cardify.om';
http_response_code(200);
foreach (['/companies', '/ar/companies/ministry-of-education-d217', '/logos', '/ar/logos/government', '/logos.php?sector=government'] as $path) {
    $_SERVER['REQUEST_URI'] = $path;
    check(CardifyAdSense::contentPage(), 'Expected public content: ' . $path);
}
foreach (['/', '/login', '/admin/index.php', '/design-card.php', '/checkout', '/company/acme', '/logos/terms', '/logos/press', '/logo-download', '/ar/privacy'] as $path) {
    $_SERVER['REQUEST_URI'] = $path;
    check(!CardifyAdSense::contentPage(), 'Ads must be excluded: ' . $path);
    ob_start(); CardifyAdSense::footer(); $html = ob_get_clean();
    check(strpos($html, 'data-ad-slot') === false, 'No ad unit on ' . $path);
}
$_SERVER['REQUEST_URI'] = '/companies';
$_SERVER['HTTP_HOST'] = 'acme.cardify.om';
check(!CardifyAdSense::contentPage(), 'Tenant pages must never carry ads');
$_SERVER['HTTP_HOST'] = 'cardify.om';
http_response_code(404);
check(!CardifyAdSense::contentPage(), 'Error pages must never carry ads');
http_response_code(200);
$method = new ReflectionMethod(SecurityHeaders::class, 'buildCsp');
if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
$_SERVER['REQUEST_URI'] = '/login';
check(strpos($method->invoke(null, 'test'), 'googlesyndication') === false, 'Private CSP stays restricted');
$_SERVER['REQUEST_URI'] = '/companies';
check(strpos($method->invoke(null, 'test'), 'pagead2.googlesyndication.com') !== false, 'Public CSP supports ads');
$GLOBALS['testDir'] = 'rtl';
ob_start(); CardifyAdSense::footer(); $html = ob_get_clean();
check(strpos($html, 'إعلان') !== false, 'Arabic ad label');
check(strpos($html, 'data-ad-slot="1547426434"') !== false, 'Actual Google unit');
check(strpos($html, ' nonce=') !== false, 'Scripts retain CSP nonces');
check(trim(file_get_contents(__DIR__ . '/../../ads.txt')) === 'google.com, pub-4720055706897611, DIRECT, f08c47fec0942fa0', 'Actual Google ads.txt entry');
echo "AdSense route, tenant, error, CSP and Arabic contracts passed.\n";
