<?php
/**
 * Save card image/PDF uploaded from batch generation
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

header('Content-Type: application/json');

// Require authentication
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Require a valid company context
$companyId = getCurrentCompanyId();
if (!$companyId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No company context']);
    exit;
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded');
    }
    
    // Get side (front or back)
    $side = isset($_POST['side']) ? $_POST['side'] : 'front';
    if (!in_array($side, ['front', 'back'])) {
        $side = 'front';
    }
    
    // Get file type
    $fileType = isset($_POST['file_type']) ? $_POST['file_type'] : 'png';
    
    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $_FILES['image']['tmp_name']);
    finfo_close($finfo);
    
    $allowedTypes = ['image/png', 'application/pdf'];
    if (!in_array($mimeType, $allowedTypes)) {
        throw new Exception('Invalid file type: ' . $mimeType);
    }

    // Create output directory (scoped per company — companyId validated above)
    $outputDir = getCompanyCardsDir($companyId);
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    // Generate unique filename based on type
    $isWeb = !empty($_POST['web']);
    $ext = ($mimeType === 'application/pdf') ? 'pdf' : 'png';
    $webSuffix = $isWeb ? '_web' : '';
    $filename = 'card_' . $side . $webSuffix . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $filePath = $outputDir . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $filePath)) {
        throw new Exception('Failed to save file');
    }
    
    // Build correct URL
    $storedPath = getWebPath($filePath);
    $url = imageUrl($storedPath);
    
    echo json_encode([
        'success' => true,
        'url' => $url,
        'filename' => $filename,
        'side' => $side,
        'type' => $ext
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

