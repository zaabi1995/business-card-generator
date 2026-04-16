<?php
/**
 * Custom Domain Router
 *
 * If the incoming Host header matches a verified row in
 * `employee_custom_domains`, serve that employee's digital card.
 * Otherwise return false so the normal routing continues unchanged.
 *
 * This file MUST be safe to include unconditionally — it never affects
 * traffic on cardify.om itself.
 */

if (!defined('CARDIFY_BOOTED')) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/includes/CustomDomain.php';

(function () {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return;

    $host = CustomDomain::normalizeHost($host);

    // Bail for primary hosts — never match.
    if (in_array($host, CustomDomain::primaryHosts(), true)) return;

    // Only run if DB is available
    if (!class_exists('DatabaseAdapter') || !DatabaseAdapter::useDatabase()) return;

    try {
        $row = CustomDomain::findVerifiedByHost($host);
    } catch (Exception $e) {
        error_log('CustomDomain lookup failed: ' . $e->getMessage());
        return;
    }

    if (!$row) {
        // Unknown host that resolves here. 404.
        http_response_code(404);
        if (file_exists(__DIR__ . '/404.php')) {
            require __DIR__ . '/404.php';
        } else {
            echo 'Not Found';
        }
        exit;
    }

    // Resolve company + employee
    $employee = findEmployeeById($row['employee_id'], $row['company_id']);
    $company  = findCompanyById($row['company_id']);
    if (!$employee || !$company) {
        http_response_code(404);
        if (file_exists(__DIR__ . '/404.php')) {
            require __DIR__ . '/404.php';
        }
        exit;
    }

    // Forward to digital_card.php (its existing flow uses these GET params)
    $_GET['company_slug'] = $company['slug'];
    $_GET['employee_id']  = $employee['id'];

    require __DIR__ . '/digital_card.php';
    exit;
})();

return false;
