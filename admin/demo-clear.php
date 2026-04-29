<?php
/**
 * POST-only: wipes the 5 demo employees seeded by DemoData::seed()
 * and clears the company's sample card flag. Redirects back to the
 * dashboard with ?demo_cleared=N so the UI can toast the result.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Onboarding.php';
require_once INCLUDES_DIR . '/DemoData.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) {
    header('Location: ' . getBasePath() . 'login.php'); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo 'method not allowed';
    exit;
}

validateCSRFToken($_POST['csrf_token'] ?? '');

$n = DemoData::clear($companyId);

$back = getAdminBasePath() . 'index' . ((defined('COMPANY_ADMIN_BASE') || !empty($_SESSION['company_slug'])) ? '' : '.php');
header('Location: ' . $back . '?demo_cleared=' . (int) $n);
exit;
