<?php
/**
 * Log card generation
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $employeeId = $input['employee_id'] ?? '';
    $frontUrl = $input['front_url'] ?? null;
    $backUrl = $input['back_url'] ?? null;
    
    if (empty($employeeId)) {
        throw new Exception('Employee ID required');
    }
    
    // Get template IDs
    $config = loadTemplates();
    $frontTemplateId = $config['activeFrontId'] ?? null;
    $backTemplateId = $config['activeBackId'] ?? null;
    
    // Log the generation
    $entry = logGeneratedCard(
        $employeeId,
        $frontTemplateId,
        $backTemplateId,
        $frontUrl ? basename($frontUrl) : null,
        $backUrl ? basename($backUrl) : null
    );
    
    echo json_encode(['success' => true, 'entry' => $entry]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

