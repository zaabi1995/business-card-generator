<?php
/**
 * Company Admin Logout
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/AuditLog.php';

// Log the logout event before destroying session
if (isset($_SESSION['user_id']) || isset($_SESSION['company_id'])) {
    $userId = $_SESSION['user_id'] ?? $_SESSION['company_id'] ?? null;
    $userEmail = $_SESSION['user_email'] ?? $_SESSION['admin_email'] ?? 'unknown';
    $userRole = $_SESSION['user_role'] ?? 'company_admin';
    $companyId = $_SESSION['company_id'] ?? null;
    
    AuditLog::log('logout', 'user', $userId, null, [
        'email' => $userEmail,
        'role' => $userRole
    ], $companyId);
}

logoutCompanyAdmin();
header('Location: ' . getBasePath() . 'login.php');
exit;

