<?php
/**
 * Send Generated Card via Email
 * Called after card generation to email cards to employees
 * Supports proof sheets and multiple recipients (employee, admin, department admin)
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/ProofSheet.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid input');
    }
    
    $employeeEmail = $input['employee_email'] ?? '';
    $employeeName = $input['employee_name'] ?? '';
    $position = $input['position'] ?? '';
    $frontPng = $input['front_png'] ?? null;
    $backPng = $input['back_png'] ?? null;
    $frontPdf = $input['front_pdf'] ?? null;
    $backPdf = $input['back_pdf'] ?? null;
    
    // Optional: additional employee data for proof sheet
    $employeeId = $input['employee_id'] ?? null;
    $sendProof = $input['send_proof'] ?? true;
    $ccAdmin = $input['cc_admin'] ?? false;
    $ccDepartmentAdmin = $input['cc_department_admin'] ?? false;
    
    if (empty($employeeEmail)) {
        throw new Exception('Employee email is required');
    }
    
    if (!isValidEmail($employeeEmail)) {
        throw new Exception('Invalid email address');
    }
    
    // Get company info
    $companyId = getCurrentCompanyId();
    $company = findCompanyById($companyId);
    $companyName = $company['name_en'] ?? $company['name'] ?? 'Company';
    
    // Convert URL to file path for attachments
    $basePath = BASE_DIR;
    
    // Helper to convert URL to file path
    $urlToPath = function($url) use ($basePath) {
        if (empty($url)) return null;
        // Remove base URL and leading slash
        $relativePath = preg_replace('#^https?://[^/]+/#', '', $url);
        $relativePath = ltrim($relativePath, '/');
        $fullPath = $basePath . '/' . $relativePath;
        return file_exists($fullPath) ? $fullPath : null;
    };
    
    // Get file paths
    $frontPngPath = $urlToPath($frontPng);
    $backPngPath = $urlToPath($backPng);
    $frontPdfPath = $urlToPath($frontPdf);
    $backPdfPath = $urlToPath($backPdf);
    
    if (!$frontPngPath && !$frontPdfPath) {
        throw new Exception('No card files to attach');
    }
    
    // Get full employee data if ID provided
    $employeeData = null;
    if ($employeeId) {
        $employeeData = findEmployeeById($employeeId, $companyId);
    }
    
    // Build employee data array for proof sheet
    $employeeInfo = [
        'id' => $employeeId,
        'name_en' => $employeeData['name_en'] ?? $employeeName,
        'name_ar' => $employeeData['name_ar'] ?? '',
        'position_en' => $employeeData['position_en'] ?? $position,
        'position_ar' => $employeeData['position_ar'] ?? '',
        'email' => $employeeData['email'] ?? $employeeEmail,
        'phone' => $employeeData['phone'] ?? '',
        'mobile' => $employeeData['mobile'] ?? '',
        'department_name' => $employeeData['department_name'] ?? ''
    ];
    
    // Get department info if available
    if ($employeeData && !empty($employeeData['department_id'])) {
        $department = findDepartmentById($employeeData['department_id'], $companyId);
        if ($department) {
            $employeeInfo['department_name'] = $department['name_en'] ?? $department['name'] ?? '';
        }
    }
    
    // Get template settings for card dimensions
    $templateSettings = null;
    try {
        $frontTemplate = getActiveFrontTemplate($companyId);
        if ($frontTemplate) {
            $templateSettings = $frontTemplate;
        }
    } catch (Exception $e) {
        // Template settings not available, will use defaults
    }
    
    // Generate proof sheet if sending with proof
    $proofSheetPath = null;
    if ($sendProof && $frontPngPath && $backPngPath) {
        $proofSheetPath = ProofSheet::generate(
            $frontPngPath,
            $backPngPath,
            $employeeInfo,
            $company,
            ['template_settings' => $templateSettings]
        );
    }
    
    // Build recipients list
    $recipients = [
        ['email' => $employeeEmail, 'name' => $employeeInfo['name_en'], 'type' => 'employee']
    ];
    
    // Add company admin if requested
    if ($ccAdmin && !empty($company['admin_email'])) {
        $recipients[] = [
            'email' => $company['admin_email'],
            'name' => $company['admin_name'] ?? 'Admin',
            'type' => 'admin'
        ];
    }
    
    // Add department admin if requested
    if ($ccDepartmentAdmin && $employeeData && !empty($employeeData['department_id'])) {
        $department = findDepartmentById($employeeData['department_id'], $companyId);
        if ($department && !empty($department['admin_email'])) {
            $recipients[] = [
                'email' => $department['admin_email'],
                'name' => $department['admin_name'] ?? 'Department Admin',
                'type' => 'department_admin'
            ];
        }
    }
    
    // Send using proof template with inline images
    $frontImagePath = $frontPngPath ?: $frontPdfPath;
    $backImagePath = $backPngPath ?: $backPdfPath;
    
    $results = Mailer::sendCardProof(
        $recipients,
        $employeeInfo,
        $company,
        $frontImagePath,
        $backImagePath,
        $proofSheetPath
    );
    
    // Check results
    $allSuccess = true;
    $errors = [];
    foreach ($results as $email => $result) {
        if (!$result['success']) {
            $allSuccess = false;
            $errors[$email] = $result['error'];
        }
    }
    
    if ($allSuccess) {
        echo json_encode([
            'success' => true,
            'message' => 'Email sent to ' . count($recipients) . ' recipient(s)',
            'recipients' => array_column($recipients, 'email'),
            'proof_attached' => !empty($proofSheetPath)
        ]);
    } else {
        // Partial success
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        echo json_encode([
            'success' => $successCount > 0,
            'message' => "Sent to {$successCount} of " . count($recipients) . " recipients",
            'errors' => $errors
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
