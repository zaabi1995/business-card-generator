<?php
/**
 * Admin "Login as" impersonation handler.
 *
 * POST ?action=start&company_id={id} , super admin starts impersonating a company admin
 * POST ?action=stop                   , return to admin session
 *
 * Security: super_admin role + CSRF required. All actions audit-logged.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';
require_once INCLUDES_DIR . '/Impersonation.php';
require_once INCLUDES_DIR . '/functions.php';

function impersonate_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    $basePath = function_exists('getBasePath') ? getBasePath() : '/';
    // Send them back to a safe place with an error flash.
    $_SESSION['impersonate_error'] = $msg;
    header('Location: ' . $basePath . 'admin/super/companies.php');
    exit;
}

// Only POST, impersonation is a state-changing action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    impersonate_fail('Method not allowed', 405);
}

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($token)) {
    impersonate_fail('Invalid CSRF token', 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'start') {
    // Must be super_admin at the moment of start (not while already impersonating).
    if (Impersonation::isActive()) {
        impersonate_fail('Already impersonating, exit first', 409);
    }
    if (!Auth::isLoggedIn() || Auth::getCurrentRole() !== 'super_admin') {
        impersonate_fail('Only super admins can impersonate', 403);
    }

    $companyId = $_POST['company_id'] ?? $_GET['company_id'] ?? '';
    if ($companyId === '') {
        impersonate_fail('Missing company_id');
    }

    $result = Impersonation::start($companyId);
    if (!$result['success']) {
        impersonate_fail($result['error'] ?? 'Failed to start impersonation', 400);
    }
    header('Location: ' . $result['redirect']);
    exit;
}

if ($action === 'stop') {
    // Stop is allowed for whoever holds the stash, they're the admin.
    $result = Impersonation::stop();
    header('Location: ' . ($result['redirect'] ?? getBasePath() . 'admin/super/companies.php'));
    exit;
}

impersonate_fail('Unknown action');
