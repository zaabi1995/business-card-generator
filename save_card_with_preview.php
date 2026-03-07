<?php
/**
 * Save card PDF with PNG preview
 * Saves both files with matching names for easy lookup
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    // Check if files were uploaded
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No PDF uploaded');
    }
    if (!isset($_FILES['preview']) || $_FILES['preview']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No preview uploaded');
    }
    
    // Get side (front or back)
    $side = isset($_POST['side']) ? $_POST['side'] : 'front';
    if (!in_array($side, ['front', 'back'])) {
        $side = 'front';
    }
    
    $companyId = getCurrentCompanyId();

    // Create output directory
    $outputDir = $companyId ? getCompanyCardsDir($companyId) : CARDS_DIR;
    if (!file_exists($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    // Generate base filename (same for both files)
    $baseName = 'card_' . $side . '_' . date('Ymd_His') . '_' . uniqid();
    
    // Save PDF
    $pdfFilename = $baseName . '.pdf';
    $pdfPath = $outputDir . '/' . $pdfFilename;
    if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfPath)) {
        throw new Exception('Failed to save PDF');
    }
    
    // Save preview PNG
    $previewFilename = $baseName . '_preview.png';
    $previewPath = $outputDir . '/' . $previewFilename;
    if (!move_uploaded_file($_FILES['preview']['tmp_name'], $previewPath)) {
        // Clean up PDF if preview fails
        @unlink($pdfPath);
        throw new Exception('Failed to save preview');
    }
    
    // Build URLs
    $pdfStoredPath = getWebPath($pdfPath);
    $previewStoredPath = getWebPath($previewPath);
    
    echo json_encode([
        'success' => true,
        'pdf_url' => imageUrl($pdfStoredPath),
        'preview_url' => imageUrl($previewStoredPath),
        'pdf_filename' => $pdfFilename,
        'preview_filename' => $previewFilename,
        'side' => $side
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
