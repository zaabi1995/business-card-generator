<?php
/**
 * Company Slug Router
 * Handles routing for company-specific pages
 * Example: cardify.om/acme/ -> company/index.php
 */
require_once __DIR__ . '/config.php';

// Get company slug from query string (set by .htaccess) or URI
$companySlug = $_GET['company_slug'] ?? null;

if (!$companySlug) {
    // Try to extract from URI
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $basePath = getBasePath();
    $pathAfterBase = str_replace($basePath, '', $requestUri);
    $pathParts = explode('/', trim($pathAfterBase, '/'));
    $companySlug = $pathParts[0] ?? null;
}

// Known paths that should not be treated as company slugs
$knownPaths = [
    'admin', 'login', 'logout', 'install', 'share', 's', 'r', 'company',
    'webhooks', 'amwalpay', 'generate_card_html.php', 'download_card.php',
    'index.php', 'router.php', 's.php', 'r.php', 'assets', 'uploads', 'data'
];

// Check if it's a known path
if (empty($companySlug) || in_array($companySlug, $knownPaths) || strpos($companySlug, '.') !== false) {
    // Not a company slug, let normal routing handle it
    return false;
}

// Check if it's a company slug
$company = findCompanyBySlug($companySlug);

if ($company) {
    // Canonicalize public company pages to {slug}.cardify.om too.
    // Bare cardify.om/{slug}/anything -> 301 {slug}.cardify.om/anything,
    // preserving any deeper path (employee slug, /portal, /card, etc).
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if (in_array($host, ['cardify.om', 'www.cardify.om'], true) && ($company['status'] ?? 'active') === 'active') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        // Strip the leading /{slug} from the path, keep the rest.
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $stripped = preg_replace('#^/' . preg_quote($companySlug, '#') . '#', '', $path);
        if ($stripped === '' || $stripped === false) $stripped = '/';
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        parse_str($qs, $params);
        unset($params['company_slug']);
        $qsOut = http_build_query($params);
        $target = $scheme . '://' . $companySlug . '.cardify.om' . $stripped . ($qsOut ? '?' . $qsOut : '');
        header('Location: ' . $target, true, 301);
        exit;
    }

    // Include company page (fires on localhost, dev envs, or when host
    // is already the subdomain).
    $_GET['company_slug'] = $companySlug;
    require __DIR__ . '/company/index.php';
    exit;
}

// Unknown slug: render the real 404 page (with status 404) instead of an
// empty 200 body. The nginx catch-all routes every `/xxx` here; without this,
// /signup, /pricing, /foo-bar all returned a blank 200, which is worse than a
// 404 for users AND for search engines.
http_response_code(404);
$notFound = __DIR__ . '/404.php';
if (is_file($notFound)) {
    require $notFound;
} else {
    echo '<!doctype html><title>404</title><h1>Not found</h1>';
}
exit;
