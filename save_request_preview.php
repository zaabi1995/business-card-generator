<?php
/**
 * Save preview card images for a card request
 * Called from the portal when employee submits their request
 * Saves front and/or back PNG previews and returns URLs
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    // Must have company ID from request
    $companyId = $_POST['company_id'] ?? null;
    if (!$companyId) {
        throw new Exception('No company ID provided');
    }
    
    // Request ID (optional - if provided, update the request record)
    $requestId = $_POST['request_id'] ?? null;
    
    // Create output directory for request previews
    $outputDir = getCompanyUploadsPath($companyId) . '/request_previews';
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    $result = [
        'success' => true,
        'front_url' => null,
        'back_url' => null
    ];
    
    // Generate unique base name
    $baseName = 'preview_' . date('Ymd_His') . '_' . uniqid();
    
    // Save front PNG if provided
    if (isset($_FILES['front']) && $_FILES['front']['error'] === UPLOAD_ERR_OK) {
        $frontFilename = $baseName . '_front.png';
        $frontPath = $outputDir . '/' . $frontFilename;
        if (move_uploaded_file($_FILES['front']['tmp_name'], $frontPath)) {
            $result['front_url'] = 'uploads/companies/' . $companyId . '/request_previews/' . $frontFilename;
        }
    }
    
    // Save back PNG if provided
    if (isset($_FILES['back']) && $_FILES['back']['error'] === UPLOAD_ERR_OK) {
        $backFilename = $baseName . '_back.png';
        $backPath = $outputDir . '/' . $backFilename;
        if (move_uploaded_file($_FILES['back']['tmp_name'], $backPath)) {
            $result['back_url'] = 'uploads/companies/' . $companyId . '/request_previews/' . $backFilename;
        }
    }
    
    // If request ID provided and we have previews, update the request
    if ($requestId && ($result['front_url'] || $result['back_url'])) {
        $db = Database::getInstance();
        if ($db->isConnected()) {
            $updateData = ['preview_generated_at' => date('Y-m-d H:i:s')];
            if ($result['front_url']) {
                $updateData['preview_front'] = $result['front_url'];
            }
            if ($result['back_url']) {
                $updateData['preview_back'] = $result['back_url'];
            }
            
            $db->update('card_requests', $updateData, 'id = :where_id', ['where_id' => $requestId]);
        }
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
