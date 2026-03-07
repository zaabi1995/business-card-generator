<?php
/**
 * VCF File Endpoint
 * Serves vCard files for employees with QR scan tracking
 */

// Start output buffering early
ob_start();

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require_once __DIR__ . '/config.php';
    require_once INCLUDES_DIR . '/VCF.php';
    require_once INCLUDES_DIR . '/QRTracker.php';
    
    // Get parameters from URL rewrite or query string
    $companySlug = $_GET['company'] ?? '';
    $employeeEmail = $_GET['email'] ?? '';
    
    // Clean up parameters
    $companySlug = trim(urldecode($companySlug));
    $employeeEmail = trim(urldecode($employeeEmail));
    $employeeEmail = preg_replace('/\.vcf$/i', '', $employeeEmail);
    
    if (empty($companySlug) || empty($employeeEmail)) {
        throw new Exception('Missing parameters');
    }
    
    // Find company
    $company = findCompanyBySlug($companySlug);
    if (!$company) {
        throw new Exception('Company not found: ' . $companySlug);
    }
    
    // Find employee
    $employee = findEmployeeByEmail($employeeEmail, $company['id']);
    if (!$employee) {
        throw new Exception('Employee not found: ' . $employeeEmail);
    }
    
    // Track the scan
    try {
        $trackResult = QRTracker::logScan($employee['id'], $company['id']);
        if (!$trackResult['success']) {
            error_log("QR tracking returned error: " . ($trackResult['error'] ?? 'Unknown error'));
        } else {
            error_log("QR scan tracked successfully: employee={$employee['id']}, scan_id={$trackResult['scan_id']}");
        }
    } catch (Throwable $e) {
        // Log but don't fail - tracking is secondary to serving VCF
        error_log("QR tracking failed with exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }
    
    // Generate VCF content
    $vcfContent = VCF::generate($employee, $company);
    
    if (empty($vcfContent)) {
        throw new Exception('Empty VCF content');
    }
    
    // Prepare filename
    $displayName = $employee['name_en'] ?? $employee['name'] ?? $employeeEmail;
    $filename = VCF::sanitizeFilename($displayName) . '.vcf';
    
    // Clear any buffered output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Send VCF response with headers to force download
    header('Content-Type: text/vcard; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($vcfContent));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    // Prevent browser from caching or displaying inline
    header('X-Content-Type-Options: nosniff');
    header('X-Download-Options: noopen');
    
    echo $vcfContent;
    exit;
    
} catch (Throwable $e) {
    // Clear buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    error_log('VCF Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error: Unable to generate contact file. Please try again later.';
    exit;
}
