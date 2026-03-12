<?php
/**
 * Log card generation
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

header('Content-Type: application/json');

try {
    // Require authentication
    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $employeeId = $input['employee_id'] ?? '';
    $frontUrl = $input['front_url'] ?? null;
    $backUrl = $input['back_url'] ?? null;
    
    if (empty($employeeId)) {
        throw new Exception('Employee ID required');
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
        $frontUrl ? basename($frontUrl) : null,
        $backUrl ? basename($backUrl) : null,
        null,
        $companyId
    );

    if (!$entry) {
        throw new Exception('Failed to log generation to database');
    }
    
    echo json_encode(['success' => true, 'entry' => $entry]);
    
} catch (Exception $e) {
    error_log("log_generation: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to log generation']);
}

