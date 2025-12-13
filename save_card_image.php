<?php
/**
 * Save card image uploaded from html2canvas
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    requireCsrf();

    // Allow only:
    // - company admin sessions (batch generation)
    // - employee session generating their own card
    $postedEmployeeId = $_POST['employee_id'] ?? null;
    if (isMultiTenantEnabled()) {
        // In multi-tenant mode, require company context for file placement/isolation.
        if (!getCurrentCompanyId()) {
            throw new Exception('Company context required');
        }
    }
    if (!isCompanyAdminLoggedIn() && !isAdminLoggedIn()) {
        // Employee flow: must be logged in and match employee_id (if provided)
        requireEmployee($postedEmployeeId ?: null);
    }

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
    $previewUrl = getBasePath() . 'download_card.php?side=' . urlencode($side)
        . '&' . ($side === 'back' ? 'back' : 'front') . '=' . urlencode($filename)
        . '&disposition=inline';
    $downloadUrl = getBasePath() . 'download_card.php?side=' . urlencode($side)
        . '&' . ($side === 'back' ? 'back' : 'front') . '=' . urlencode($filename)
        . '&disposition=attachment';

    // Remember last generated filenames for employee downloads (prevents guessing).
    ensureSessionStarted();
    if (!empty($postedEmployeeId) && isEmployeeLoggedIn() && ($_SESSION['employee_id'] ?? null) === $postedEmployeeId) {
        if ($side === 'front') {
            $_SESSION['employee_last_front_file'] = $filename;
        } else {
            $_SESSION['employee_last_back_file'] = $filename;
        }
    }
    
    echo json_encode([
        'success' => true,
        // Backwards-compatible: `url` is usable for previewing in <img>.
        'url' => $previewUrl,
        'preview_url' => $previewUrl,
        'download_url' => $downloadUrl,
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

