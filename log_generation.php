<?php
/**
 * Log card generation
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    requireCsrf();

    $input = json_decode(file_get_contents('php://input'), true);
    
    $employeeId = $input['employee_id'] ?? '';
    $frontFile = $input['front_file'] ?? null;
    $backFile = $input['back_file'] ?? null;
    // Backwards compatibility (older clients)
    $frontUrl = $input['front_url'] ?? null;
    $backUrl = $input['back_url'] ?? null;
    
    if (empty($employeeId)) {
        throw new Exception('Employee ID required');
    }

    // Only admins, or the employee generating their own card, may log generation
    if (!isCompanyAdminLoggedIn() && !isAdminLoggedIn()) {
        requireEmployee($employeeId);
    }
    
    // Get template IDs
    $companyId = getCurrentCompanyId();
    $config = loadTemplates($companyId);
    $frontTemplateId = $config['activeFrontId'] ?? null;
    $backTemplateId = $config['activeBackId'] ?? null;
    
    // Log the generation
    $entry = logGeneratedCard(
        $employeeId,
        $frontTemplateId,
        $backTemplateId,
        $frontFile ? basename($frontFile) : ($frontUrl ? basename($frontUrl) : null),
        $backFile ? basename($backFile) : ($backUrl ? basename($backUrl) : null),
        null,
        $companyId
    );
    
    echo json_encode(['success' => true, 'entry' => $entry]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

