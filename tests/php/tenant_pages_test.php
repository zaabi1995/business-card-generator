<?php
declare(strict_types=1);

// Run: php tests/php/tenant_pages_test.php
// Guards the Mehdi Store bag QR target (https://mehdistore.cardify.om/main) and
// the rule that tenant pages never touch any other tenant's routing.

$root = dirname(__DIR__, 2);
require $root . '/includes/TenantPages.php';

$fail = 0;
$check = static function (bool $ok, string $what) use (&$fail): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: $what\n");
        $fail++;
    }
};

// ---- routing ------------------------------------------------------------
$r = TenantPages::route('mehdistore.cardify.om', '/main');
$check($r['action'] === 'serve' && basename($r['file'] ?? '') === 'main.php', '/main serves main.php');
$r = TenantPages::route('MehdiStore.cardify.om:443', '/main?lang=en&utm_source=bag');
$check($r['action'] === 'serve', 'host is case/port insensitive and query is ignored for routing');
$r = TenantPages::route('mehdistore.cardify.om', '/main/?lang=en');
$check($r['action'] === 'redirect' && $r['location'] === '/main?lang=en' && $r['status'] === 301, '/main/ 301s to /main keeping the query');
$r = TenantPages::route('mehdistore.cardify.om', '/?lang=en');
$check($r['action'] === 'redirect' && $r['location'] === '/main?lang=en' && $r['status'] === 302, 'bare subdomain 302s to /main');
$r = TenantPages::route('mehdistore.cardify.om', '/main', 'POST');
$check($r['action'] === 'method' && $r['status'] === 405, 'POST is 405');
foreach (['/login', '/portal', '/_site', '/content', '/main.php', '/Main', '/main/x', '/..%2fmain'] as $p) {
    $check(TenantPages::route('mehdistore.cardify.om', $p)['action'] === 'pass', "$p falls through to normal routing");
}
foreach (['otech.cardify.om', 'cardify.om', 'www.cardify.om', 'mehdistore.cardify.om.evil.com', 'mehdistore.example.com'] as $h) {
    $check(TenantPages::route($h, '/main')['action'] === 'pass', "$h/main is untouched");
    $check(TenantPages::route($h, '/')['action'] === 'pass', "$h/ is untouched");
}

// ---- content ------------------------------------------------------------
$dir = $root . '/includes/tenant-pages/mehdistore';
$data = json_decode((string) file_get_contents($dir . '/content.json'), true);
$check(is_array($data), 'content.json parses');
$urls = [];
array_walk_recursive($data, static function ($v, $k) use (&$urls): void {
    if (in_array($k, ['url', 'map'], true)) {
        $urls[] = $v;
    }
});
foreach ($urls as $u) {
    $check((bool) preg_match('~^https://[^\s"<>]+$~', $u), "https url: $u");
    $check(strpos($u, 'wa.me') === false, "no wa.me link: $u");
}
$branches = 0;
foreach ($data['branch_groups'] as $g) {
    $branches += count($g['branches']);
}
$check($branches === 12, '12 branches');
$check($data['whatsapp']['url'] === 'https://api.whatsapp.com/send?phone=96899093315', 'primary WhatsApp link');

// ---- render -------------------------------------------------------------
foreach (['ar', 'en'] as $lang) {
    $_GET = $lang === 'en' ? ['lang' => 'en'] : [];
    $tenantPage = [
        'slug' => 'mehdistore', 'page' => 'main', 'dir' => $dir,
        'origin' => 'https://mehdistore.cardify.om',
        'asset_base' => '/assets/tenant-pages/mehdistore/',
        'asset_dir' => $root . '/assets/tenant-pages/mehdistore',
    ];
    ob_start();
    require $dir . '/main.php';
    $html = (string) ob_get_clean();
    $check(substr_count($html, 'class="branch"') === 12, "$lang: 12 branch cards");
    $check(strpos($html, '<html lang="' . $lang . '"') !== false, "$lang: html lang");
    $check(strpos($html, "\u{2014}") === false, "$lang: no em dash");
    $check(strpos($html, 'og:image" content="https://mehdistore.cardify.om/assets/tenant-pages/mehdistore/og.jpg?v=') !== false, "$lang: absolute og:image");
    foreach ($urls as $u) {
        $check(strpos($html, 'href="' . htmlspecialchars($u, ENT_QUOTES, 'UTF-8') . '"') !== false, "$lang: link rendered verbatim: $u");
    }
}
foreach (['logo.svg', 'skyline.svg', 'favicon.svg', 'favicon-32.png', 'apple-touch-icon.png', 'og.jpg',
          'icons-light.woff2', 'icons-solid.woff2', 'icons-brands.woff2'] as $asset) {
    $check(is_file($root . '/assets/tenant-pages/mehdistore/' . $asset), "asset present: $asset");
}

if ($fail > 0) {
    fwrite(STDERR, "$fail check(s) failed\n");
    exit(1);
}
echo "tenant_pages_test: all checks passed\n";
