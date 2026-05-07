<?php
/**
 * Get Templates API - Returns active templates for the current company
 */

// Only load config if not already loaded (when accessed directly)
if (!defined('BASE_DIR')) {
    require_once __DIR__ . '/../config.php';
}

// Set JSON header
header('Content-Type: application/json');

// If routed via company_admin.php, auth is already done
// If accessed directly, check auth
if (!defined('COMPANY_ADMIN_BASE')) {
    require_once INCLUDES_DIR . '/Auth.php';
    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$companyId = getCurrentCompanyId();
if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'No company context']);
    exit;
}

try {
    $config = loadTemplates($companyId);
    echo json_encode($config);
} catch (Exception $e) {
    error_log('[admin/get_templates] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load templates']);
}
