<?php
/**
 * QR Code Tracking Endpoint
 *
 * Serves VCF contact file with scan tracking.
 * Supports two URL formats:
 *   Short (preferred): /qr.php?i={employee_id}  — smallest QR code
 *   Legacy:            /qr.php?c={slug}&e={email}
 */

// Buffer output to prevent header issues
ob_start();

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/QRTracker.php';
require_once INCLUDES_DIR . '/VCF.php';

// Validate parameters
$employeeId   = trim($_GET['i'] ?? '');
$companySlug  = urldecode($_GET['c'] ?? $_GET['company'] ?? '');
$employeeEmail = urldecode($_GET['e'] ?? $_GET['email'] ?? '');

if (empty($employeeId) && (empty($companySlug) || empty($employeeEmail))) {
    ob_end_clean();
    http_response_code(400);
    die('Invalid request');
}

try {
    $db = Database::getInstance();

    if (!empty($employeeId)) {
        // Short format: look up by employee ID directly
        // NOTE: `companies` table only has `name` + `country` (no name_en/name_ar/website/address/city).
        // VCF::generate() null-coalesces missing keys, so we only select columns that exist.
        $employee = $db->fetchOne(
            "SELECT e.*, c.id as company_id, c.slug, c.name as company_name, c.country
             FROM employees e
             JOIN companies c ON c.id = e.company_id
             WHERE e.id = :id AND e.status = 'active' LIMIT 1",
            ['id' => $employeeId]
        );
        // Fallback: the same id may belong to a pending/approved card_requests
        // row (E-Card for an un-approved request). Serve a VCF built from it
        // so the Save Contact button works even before admin approval.
        if (!$employee) {
            $req = $db->fetchOne(
                "SELECT r.*, c.id AS _cid, c.slug AS _cslug, c.name AS _cname, c.country AS _ccountry
                   FROM card_requests r
                   JOIN companies c ON c.id = r.company_id
                  WHERE r.id = :id AND r.status IN ('pending','approved')
                  LIMIT 1",
                ['id' => $employeeId]
            );
            if ($req) {
                $employee = $req;
                $employee['company_id']   = $req['_cid'];
                $employee['slug']         = $req['_cslug'];
                $employee['company_name'] = $req['_cname'];
                $employee['country']      = $req['_ccountry'] ?? '';
            }
        }
        $company = $employee ? [
            'id'      => $employee['company_id'],
            'slug'    => $employee['slug'],
            'name'    => $employee['company_name'],
            'country' => $employee['country'] ?? '',
        ] : null;
    } else {
        // Legacy format: slug + email
        $company = findCompanyBySlug($companySlug);
        if (!$company) {
            ob_end_clean();
            http_response_code(404);
            die('Company not found');
        }
        $employee = findEmployeeByEmail($employeeEmail, $company['id']);
    }

    if (!$company) {
        ob_end_clean();
        http_response_code(404);
        die('Company not found');
    }

    if (!$employee) {
        ob_end_clean();
        http_response_code(404);
        die('Employee not found');
    }

    // Log the scan
    try {
        QRTracker::logScan($employee['id'], $company['id']);
    } catch (Exception $e) {
        error_log("QR tracking error: " . $e->getMessage());
    }

    // Dynamic QR: if this employee has a custom redirect URL set,
    // 302-redirect to it instead of serving the VCF. Lets owners
    // change a printed card's destination without reprinting.
    $redirectUrl = trim((string)($employee['qr_redirect_url'] ?? ''));
    if ($redirectUrl !== ''
        && filter_var($redirectUrl, FILTER_VALIDATE_URL)
        && preg_match('~^https?://~i', $redirectUrl)) {
        ob_end_clean();
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Location: ' . $redirectUrl, true, 302);
        exit;
    }

    // Generate VCF content
    $vcfContent = VCF::generate($employee, $company);
    $filename = VCF::sanitizeFilename($employee['name_en'] ?? $employee['email']) . '.vcf';

    // Clear buffer and send VCF
    ob_end_clean();
    
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($vcfContent));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $vcfContent;
    exit;

} catch (Exception $e) {
    ob_end_clean();
    error_log('QR/VCF error: ' . $e->getMessage());
    http_response_code(500);
    die('Server error');
}
