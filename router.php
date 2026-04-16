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
    'admin', 'login', 'logout', 'install', 'share', 's', 'company',
    'webhooks', 'amwalpay', 'generate_card_html.php', 'download_card.php',
    'index.php', 'router.php', 's.php', 'assets', 'uploads', 'data'
];

// Check if it's a known path
if (empty($companySlug) || in_array($companySlug, $knownPaths) || strpos($companySlug, '.') !== false) {
    // Not a company slug, let normal routing handle it
    return false;
}

// Check if it's a company slug
$company = findCompanyBySlug($companySlug);

if ($company) {
    // Include company page
    $_GET['company_slug'] = $companySlug;
    require __DIR__ . '/company/index.php';
    exit;
}

// Not a company slug, continue normal routing
return false;
