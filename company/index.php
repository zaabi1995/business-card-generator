<?php
/**
 * Company Portal - Role-Aware Dashboard
 * Accessible via: cardify.om/{company_slug}/
 * 
 * Views:
 * - Public: Company branding + login/register CTA
 * - Company Admin: Full dashboard (templates, employees, orders, billing)
 * - Employee: Profile editor, card preview, order history
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

// Get company slug from query string or URI
$companySlug = $_GET['company_slug'] ?? null;

if (!$companySlug) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $basePath = getBasePath();
    $pathAfterBase = str_replace($basePath, '', $requestUri);
    $pathParts = explode('/', trim($pathAfterBase, '/'));
    $companySlug = $pathParts[0] ?? null;
}

if (empty($companySlug)) {
    header('Location: ' . getBasePath());
    exit;
}

// Find company
$company = findCompanyBySlug($companySlug);
if (!$company) {
    http_response_code(404);
    require_once __DIR__ . '/../404.php';
    exit;
}

// Check company status
if ($company['status'] !== 'active') {
    http_response_code(403);
    die('<h1>Company Suspended</h1><p>This company account has been suspended. Please contact support.</p>');
}

$db = Database::getInstance();

// Get company theme
$companyTheme = null;
if (DatabaseAdapter::useDatabase()) {
    try {
        $companyTheme = $db->fetchOne(
            "SELECT * FROM company_themes WHERE company_id = :id",
            ['id' => $company['id']]
        );
    } catch (Exception $e) {
        // company_themes table might not exist yet
    }
}

// Check if user is logged in and belongs to this company
$isLoggedIn = Auth::isLoggedIn();
$currentRole = Auth::getCurrentRole();
$currentCompanyId = $_SESSION['company_id'] ?? null;
$belongsToCompany = $currentCompanyId === $company['id'];

// Always show public view on the base company URL
// Admin dashboard is accessed via /{company}/admin/
// Employee view is accessed via /{company}/my-card or similar
$viewType = 'public';

// Only set company context in session for authenticated users who belong to this company
if ($isLoggedIn && $belongsToCompany) {
    setCompanyContext($company);
}

// Apply theme colors
$primaryColor = $companyTheme['primary_color'] ?? '#009bc1';
$secondaryColor = $companyTheme['secondary_color'] ?? '#036e87';
$pageTitle = sanitize($company['name']);
$metaRobots = 'noindex, nofollow';

// Get page action
$page = $_GET['page'] ?? 'dashboard';

// Always show public landing page at /{company}/
// Admin dashboard: /{company}/admin/
// Employee portal: /{company}/portal
include __DIR__ . '/views/public.php';
