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
    $frontWebUrl = $input['front_web_url'] ?? null;
    $backWebUrl = $input['back_web_url'] ?? null;
    $themeMode = $input['theme_mode'] ?? null;

    if (empty($employeeId)) {
        throw new Exception('Employee ID required');
    }

    // Get template IDs
    $companyId = getCurrentCompanyId();
    $config = loadTemplates($companyId);
    $frontTemplateId = $config['activeFrontId'] ?? null;
    $backTemplateId = $config['activeBackId'] ?? null;

    // Enforce monthly card generation limit (free/pro plans)
    require_once INCLUDES_DIR . '/Billing.php';
    $billing = new Billing();
    if (!$billing->checkLimit($companyId, 'max_cards')) {
        $limits = $billing->getPlanLimits($companyId);
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Monthly card limit reached (' . ($limits['max_cards'] ?? 10) . ' cards/month on your plan). Upgrade to generate more.',
            'limit_reached' => true,
        ]);
        exit;
    }

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

    // Update web paths and theme_mode if provided
    if ($entry && ($frontWebUrl || $backWebUrl || $themeMode)) {
        try {
            $db = Database::getInstance();
            $updateData = [];
            if ($frontWebUrl) {
                $updateData['front_web_path'] = basename($frontWebUrl);
            }
            if ($backWebUrl) {
                $updateData['back_web_path'] = basename($backWebUrl);
            }
            if ($themeMode && in_array($themeMode, ['dark', 'light'])) {
                $updateData['theme_mode'] = $themeMode;
            }
            if (!empty($updateData)) {
                $db->update('generated_cards', $updateData, 'id = :id', ['id' => $entry['id']]);
            }
        } catch (Exception $e) {
            error_log("log_generation web/theme update error: " . $e->getMessage());
        }
    }

    echo json_encode(['success' => true, 'entry' => $entry]);
    
} catch (Exception $e) {
    error_log("log_generation: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Failed to log generation']);
}

