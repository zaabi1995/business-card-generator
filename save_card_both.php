<?php
/**
 * Save both PNG and PDF versions of a card
 * PNG is always saved, PDF only if provided
 * Both use the same base filename for easy lookup
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    // Check if PNG was uploaded (required)
    if (!isset($_FILES['png']) || $_FILES['png']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No PNG uploaded');
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
    
    // Save PNG (always)
    $pngFilename = $baseName . '.png';
    $pngPath = $outputDir . '/' . $pngFilename;
    if (!move_uploaded_file($_FILES['png']['tmp_name'], $pngPath)) {
        throw new Exception('Failed to save PNG');
    }
    
    // Save PDF if provided
    $pdfFilename = null;
    $pdfPath = null;
    if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $pdfFilename = $baseName . '.pdf';
        $pdfPath = $outputDir . '/' . $pdfFilename;
        if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $pdfPath)) {
            // Don't fail completely, just log warning
            error_log("Failed to save PDF for card: $baseName");
            $pdfFilename = null;
        }
    }
    
    // Build URLs
    $pngStoredPath = getWebPath($pngPath);
    $pdfUrl = null;
    if ($pdfFilename) {
        $pdfStoredPath = getWebPath($pdfPath);
        $pdfUrl = imageUrl($pdfStoredPath);
    }
    
    echo json_encode([
        'success' => true,
        'png_url' => imageUrl($pngStoredPath),
        'pdf_url' => $pdfUrl,
        'png_filename' => $pngFilename,
        'pdf_filename' => $pdfFilename,
        'has_pdf' => $pdfFilename !== null,
        'side' => $side
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
