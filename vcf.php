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

    // On a tenant subdomain, slug comes from $host.
    if ($companySlug === '') {
        require_once INCLUDES_DIR . '/TenantHost.php';
        if (TenantHost::isTenantHost()) {
            $companySlug = (string) TenantHost::slug();
        }
    }

    if (empty($companySlug) || empty($employeeEmail)) {
        // A malformed request is the caller's error, not ours. Throwing here
        // reached the catch-all below and answered 500, which tells a monitor
        // the server is broken when nothing is.
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Missing company or contact';
        exit;
    }
    
    // Find company
    $company = findCompanyBySlug($companySlug);
    if (!$company) {
        // Not-found is 404, not 500, exactly as the employee branch below
        // already does. This one was missed: an unknown slug threw into the
        // catch-all and every crawler hitting a dead tenant link logged a
        // server error. (8 Aug 2026.)
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Contact not found';
        exit;
    }
    
    // Find employee; fall back to (a) lookup by id/slug when the URL token
    // has no '@' (e.g. /<slug>.vcf manually typed), then (b) the latest
    // card_request for this email so the QR on a watermarked preview resolves
    // to a real vCard even before the admin approves the request.
    $employee = findEmployeeByEmail($employeeEmail, $company['id']);
    if (!$employee && strpos($employeeEmail, '@') === false) {
        $employee = findEmployeeById($employeeEmail, $company['id']);
        // Domain-agnostic email-localpart fallback: covers the pretty
        // /<localpart>.vcf short URL for employees whose id is a random hex
        // (not the localpart). Mirrors index.php + digital_card.php so every
        // public surface resolves the same token. (also matches . _ - variants)
        if (!$employee) {
            $local = strtolower($employeeEmail);
            $dashed = str_replace(['.', '_'], '-', $local);
            $employee = Database::getInstance()->fetchOne(
                "SELECT * FROM employees
                  WHERE company_id = :cid AND deleted_at IS NULL
                    AND (
                         LOWER(SUBSTRING_INDEX(email, '@', 1)) = :exact
                      OR REPLACE(REPLACE(LOWER(SUBSTRING_INDEX(email, '@', 1)), '.', '-'), '_', '-') = :dashed
                    )
                  LIMIT 1",
                ['cid' => $company['id'], 'exact' => $local, 'dashed' => $dashed]
            ) ?: null;
        }
    }
    if (!$employee) {
        $db = Database::getInstance();
        $req = $db->fetchOne(
            "SELECT * FROM card_requests
              WHERE company_id = :cid AND LOWER(email) = LOWER(:em)
                AND status IN ('pending','approved')
              ORDER BY submitted_at DESC LIMIT 1",
            ['cid' => $company['id'], 'em' => $employeeEmail]
        );
        if ($req) {
            $employee = [
                'id'          => $req['id'],
                'email'       => $req['email'],
                'name_en'     => $req['name_en']     ?? '',
                'name_ar'     => $req['name_ar']     ?? '',
                'position_en' => $req['position_en'] ?? '',
                'position_ar' => $req['position_ar'] ?? '',
                'phone'       => $req['phone']       ?? '',
                'phone_ar'    => $req['phone_ar']    ?? '',
                'mobile'      => $req['mobile']      ?? '',
                'mobile_ar'   => $req['mobile_ar']   ?? '',
                'website'     => $req['website']     ?? '',
                'website_ar'  => $req['website_ar']  ?? '',
                'address_en'  => $req['address_en']  ?? '',
                'address_ar'  => $req['address_ar']  ?? '',
                'address_2_en'=> $req['address_2_en']?? '',
                'address_2_ar'=> $req['address_2_ar']?? '',
                'company_en'  => $req['company_en']  ?? $company['name'] ?? '',
                'company_ar'  => $req['company_ar']  ?? '',
            ];
        }
    }
    if (!$employee) {
        // Not-found is 404, not 500. (iter 21, 7 May 2026.)
        while (ob_get_level()) { ob_end_clean(); }
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Contact not found';
        exit;
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
