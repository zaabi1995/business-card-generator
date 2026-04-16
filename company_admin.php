<?php
/**
 * Company Admin Router
 * Routes /{company_slug}/admin/{page} to the appropriate admin page
 */

// Error handling
ini_set('log_errors', 1);

// Set up error handler to catch all errors
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/Auth.php';
    
    // Only show errors in development
    if (function_exists('isProduction') && !isProduction()) {
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', 0);
        error_reporting(E_ALL & ~E_NOTICE);
    }
    
    // Get company slug and page from URL
    $companySlug = $_GET['company_slug'] ?? '';
    $page = $_GET['page'] ?? 'index';
    
    // Validate company slug
    if (empty($companySlug)) {
        header('Location: ' . getBasePath() . 'login.php');
        exit;
    }
    
    // Find company
    $company = findCompanyBySlug($companySlug);
    if (!$company) {
        http_response_code(404);
        die('Company not found: ' . htmlspecialchars($companySlug));
    }
    
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Store minimal routing hint only — don't set company_id until ownership is verified below
    $_SESSION['current_company_slug'] = $companySlug;
    
    // Map pages to admin files
    $pageMap = [
        'index' => 'admin/index.php',
        'login' => 'login.php',
        'employees' => 'admin/employees.php',
        'departments' => 'admin/departments.php',
        'requests' => 'admin/requests.php',
        'generated' => 'admin/generated.php',
        'batch_generate' => 'admin/batch_generate.php',
        'auto_generate' => 'admin/auto_generate.php',
        'get_templates' => 'admin/get_templates.php',
        'save_template' => 'admin/save_template.php',
        'send_card_email' => 'admin/send_card_email.php',
        'analytics' => 'admin/analytics.php',
        'audit-logs' => 'admin/audit-logs.php',
        'theme' => 'admin/theme.php',
        'billing' => 'admin/billing.php',
        'print' => 'admin/print.php',
        'print_orders' => 'admin/print_orders.php',
        'print_settings' => 'admin/print_settings.php',
        'print_shops' => 'admin/print_shops.php',
        'order_print' => 'admin/order_print.php',
        'odoo_settings' => 'admin/odoo_settings.php',
        'whatsapp_settings' => 'admin/whatsapp_settings.php',
        'plans' => 'admin/plans.php',
        'companies' => 'admin/companies.php',
        'updates' => 'admin/updates.php',
        'share' => 'admin/share.php',
        'order_detail' => 'admin/order_detail.php',
        'printer' => 'admin/printer.php',
        'check-billing' => 'admin/check-billing.php',
        'order-checkout' => 'admin/order-checkout.php',
        'order-receipt'  => 'admin/order-receipt.php',
        'bhd-campaign'   => 'admin/bhd-campaign.php',
        'credit-accounts'    => 'admin/credit-accounts.php',
        'customer-dashboard' => 'admin/customer-dashboard.php',
        'payment-history'    => 'admin/payment-history.php',
        'batch-auto-generate' => 'admin/batch-auto-generate.php',
        'short-links'         => 'admin/short-links.php',
    ];
    
    // Handle login page specially (no auth required)
    if ($page === 'login') {
        $_GET['redirect'] = getBasePath() . $companySlug . '/admin/';
        include __DIR__ . '/login.php';
        exit;
    }
    
    // Check if user is logged in
    if (!Auth::isLoggedIn()) {
        header('Location: ' . getBasePath() . $companySlug . '/admin/login');
        exit;
    }
    
    // Verify user has access to this company BEFORE writing company context to session
    $userCompanyId = $_SESSION['user_company_id'] ?? null;
    $userRole = $_SESSION['user_role'] ?? null;

    // Only admin-level roles can access the company admin panel
    $adminRoles = ['super_admin', 'admin', 'company', 'company_admin'];
    if (!in_array($userRole, $adminRoles)) {
        header('Location: ' . getBasePath() . 'login.php?error=unauthorized');
        exit;
    }

    // Super admins can access any company, others must match their own company
    if ($userRole !== 'super_admin' && $userCompanyId && $userCompanyId !== $company['id']) {
        header('Location: ' . getBasePath() . 'login.php?error=unauthorized');
        exit;
    }

    // Ownership verified — now safe to set company context in session
    $_SESSION['company_slug'] = $companySlug;
    $_SESSION['company_id'] = $company['id'];
    $_SESSION['company_name'] = $company['name'] ?? $company['name_en'] ?? $companySlug;
    
    // Check if page exists in map
    if (!isset($pageMap[$page])) {
        http_response_code(404);
        die('Page not found: ' . htmlspecialchars($page));
    }
    
    // Set base path for admin pages to use company-specific URLs
    define('COMPANY_ADMIN_BASE', getBasePath() . $companySlug . '/admin/');
    
    // Include the admin page
    $adminFile = __DIR__ . '/' . $pageMap[$page];
    if (file_exists($adminFile)) {
        include $adminFile;
    } else {
        http_response_code(404);
        die('Admin file not found: ' . $pageMap[$page]);
    }
    
} catch (Throwable $e) {
    // Only set response code if headers haven't been sent yet
    if (!headers_sent()) {
        http_response_code(500);
    }
    error_log('Company Admin Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo '<div class="bg-red-50 border border-red-200 rounded-lg p-4 m-4">';
    echo '<h1 class="text-red-700 font-bold">Error</h1>';
    if (function_exists('isProduction') && !isProduction()) {
        echo '<p class="text-red-600">' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<pre class="text-xs mt-2 overflow-auto bg-red-100 p-2 rounded">' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    } else {
        echo '<p class="text-red-600">An unexpected error occurred. Please try again later.</p>';
    }
    echo '</div>';
}
