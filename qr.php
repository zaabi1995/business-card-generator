<?php
/**
 * QR Code Tracking Endpoint
 * 
 * This endpoint tracks QR code scans and serves the VCF file.
 * URL format: /qr.php?c={company_slug}&e={employee_email}
 */

// Buffer output to prevent header issues
ob_start();

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/QRTracker.php';
require_once INCLUDES_DIR . '/VCF.php';

// Get parameters
$companySlug = $_GET['c'] ?? $_GET['company'] ?? '';
$employeeEmail = $_GET['e'] ?? $_GET['email'] ?? '';

// URL decode
$companySlug = urldecode($companySlug);
$employeeEmail = urldecode($employeeEmail);

// Validate parameters
if (empty($companySlug) || empty($employeeEmail)) {
    ob_end_clean();
    http_response_code(400);
    die('Invalid request');
}

try {
    // Find company
    $company = findCompanyBySlug($companySlug);
    if (!$company) {
        ob_end_clean();
        http_response_code(404);
        die('Company not found');
    }

    // Find employee
    $employee = findEmployeeByEmail($employeeEmail, $company['id']);
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
