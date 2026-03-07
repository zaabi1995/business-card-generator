<?php
/**
 * Unified Logout
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/AuditLog.php';

// Log the logout event before destroying session
if (Auth::isLoggedIn() && isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    $userEmail = $_SESSION['user_email'] ?? 'unknown';
    $userRole = $_SESSION['user_role'] ?? 'unknown';
    $companyId = $_SESSION['company_id'] ?? null;
    
    AuditLog::log('logout', 'user', $userId, null, [
        'email' => $userEmail,
        'role' => $userRole
    ], $companyId);
}

Auth::logout();

header('Location: ' . getBasePath() . 'login.php');
exit;
