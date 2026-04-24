<?php
/**
 * og-image.php, serves a branded 1200x630 PNG for a company, used as
 * og:image on the tenant portal + login pages.
 *
 *   /og-image.php?slug=ohb
 *   /og-image.php?slug=ohb&dept=marketing
 *   /og-image.php?slug=ohb&lang=ar
 *   /og-image.php?slug=ohb&v=login
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/OgImage.php';
require_once INCLUDES_DIR . '/TenantHost.php';

$slug = strtolower(trim((string)($_GET['slug'] ?? '')));

// Fall back to the host slug when ?slug is missing (lets us serve
// /og-image.php without any query on a tenant subdomain).
if ($slug === '') {
    $slug = (string)(TenantHost::slug() ?? '');
}

$serveDefault = function () {
    $fallback = __DIR__ . '/assets/images/cardify-og.png';
    if (is_file($fallback)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        readfile($fallback);
    } else {
        http_response_code(404);
    }
    exit;
};

if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) {
    $serveDefault();
}

$company = findCompanyBySlug($slug);
if (!$company) {
    $serveDefault();
}

$locale  = in_array(($_GET['lang'] ?? ''), ['en', 'ar'], true) ? $_GET['lang'] : 'en';
$variant = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['v'] ?? 'portal')) ?: 'portal';

$deptName = '';
$deptSlug = strtolower(trim((string)($_GET['dept'] ?? '')));
if ($deptSlug !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $deptSlug)) {
    try {
        $row = Database::getInstance()->fetchOne(
            'SELECT name FROM departments WHERE company_id = :c AND slug = :s LIMIT 1',
            ['c' => $company['id'], 's' => $deptSlug]
        );
        if ($row) $deptName = (string)$row['name'];
    } catch (Throwable $_) { /* ignore */ }
}

$path = OgImage::generate($company, [
    'locale'     => $locale,
    'variant'    => $variant,
    'department' => $deptName,
]);

if (!$path || !is_file($path)) {
    $serveDefault();
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($path));
readfile($path);
