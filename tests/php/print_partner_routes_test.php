<?php
/**
 * Public print-partner signup URLs must resolve to the existing
 * printshop/register.php form, not a 404 and not a second SaaS.
 *
 * Run: php tests/php/print_partner_routes_test.php
 */

$root = dirname(__DIR__, 2);
$fails = 0;

function routeCheck(string $label, $ok, string $detail = ''): void
{
    global $fails;
    if (!$ok) {
        $fails++;
    }
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? ' , ' . $detail : '');
}

$register = $root . '/printshop/register.php';
routeCheck('printshop/register.php exists', is_file($register));

$partners = $root . '/partners.php';
routeCheck('partners.php exists at repo root', is_file($partners));

// Do not create a print-shops/ directory: nginx try_files $uri/ would
// prefer that folder over print-shops.php and 404 the marketplace.

$htaccess = (string) file_get_contents($root . '/.htaccess');
routeCheck(
    '.htaccess maps /print-shops/register to the register form',
    (bool) preg_match('#print-shops/register/?.*printshop/register\.php#', $htaccess)
);
routeCheck(
    '.htaccess maps /partners to the register form',
    (bool) preg_match('#\^partners/\?\$#', $htaccess)
);

$router = (string) file_get_contents($root . '/router.php');
routeCheck(
    'router.php treats partners as a known path, not a company slug',
    strpos($router, "'partners'") !== false || strpos($router, '"partners"') !== false
);

$notFound = (string) file_get_contents($root . '/404.php');
routeCheck(
    '404.php recovers /print-shops/register and /partners when nginx has no rewrite',
    strpos($notFound, 'print-shops/register') !== false && strpos($notFound, 'partners') !== false
);

$nginxDoc = $root . '/docs/print-partner-nginx-rewrites.conf';
routeCheck('nginx rewrite snippet exists for Master', is_file($nginxDoc));
if (is_file($nginxDoc)) {
    $nginx = (string) file_get_contents($nginxDoc);
    routeCheck(
        'nginx snippet rewrites /print-shops/register',
        strpos($nginx, 'print-shops/register') !== false
    );
    routeCheck(
        'nginx snippet rewrites /partners',
        strpos($nginx, '/partners') !== false
    );
}

$printShops = (string) file_get_contents($root . '/print-shops.php');
routeCheck(
    'marketplace CTA points at /print-shops/register, not a 404 path',
    strpos($printShops, 'print-shops/register') !== false
);

$requireAdmin = (string) file_get_contents($root . '/includes/functions.php');
routeCheck(
    'requireAdmin partner check uses the URL tenant, not session company_id alone',
    strpos($requireAdmin, 'currentSessionCanStayInCompanyAdmin') !== false
);
$adminHeader = (string) file_get_contents($root . '/includes/admin-layout.php');
routeCheck(
    'adminHeader partner check uses the URL tenant, not session company_id alone',
    strpos($adminHeader, 'currentSessionCanStayInCompanyAdmin') !== false
);
$createClient = (string) file_get_contents($root . '/printshop/create-client.php');
routeCheck(
    'create-client adopts the new company into session before redirect',
    strpos($createClient, 'adoptClientContext') !== false
);
$createCompanySrc = (string) file_get_contents($root . '/includes/DatabaseAdapter.php');
routeCheck(
    'createCompany inserts a companies row, not a second users row',
    strpos($createCompanySrc, "insert('companies'") !== false
        && strpos($createCompanySrc, "insert('users'") === false
);

if ($fails > 0) {
    echo "FAILED {$fails}\n";
    exit(1);
}

echo "ALL PASS\n";
