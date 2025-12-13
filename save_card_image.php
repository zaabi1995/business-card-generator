<?php
/**
 * Save card image uploaded from html2canvas
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    // Check if image was uploaded
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No image uploaded');
    }
    
    // Get side (front or back)
    $side = isset($_POST['side']) ? $_POST['side'] : 'front';
    if (!in_array($side, ['front', 'back'])) {
        $side = 'front';
    }
    
    // Validate it's a PNG
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $_FILES['image']['tmp_name']);
    finfo_close($finfo);
    
    if ($mimeType !== 'image/png') {
        throw new Exception('Invalid image type');
    }
    
    $companyId = getCurrentCompanyId();

    // Create output directory (scoped per company in multi-tenant mode)
    $outputDir = $companyId ? getCompanyCardsDir($companyId) : CARDS_DIR;
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    // Generate unique filename
    $filename = 'card_' . $side . '_' . date('Ymd_His') . '_' . uniqid() . '.png';
    $filePath = $outputDir . '/' . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $filePath)) {
        throw new Exception('Failed to save image');
    }
    
    // Build correct URL
    $storedPath = getWebPath($filePath);
    $url = imageUrl($storedPath);
    
    echo json_encode([
        'success' => true,
        'url' => $url,
        'filename' => $filename,
        'side' => $side
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

