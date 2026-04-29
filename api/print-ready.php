<?php
/**
 * Print-Ready Sheet Generator API
 * 
 * Generates print-ready A4 PDF sheets with cutting marks for print shops
 * Uses TCPDF for professional quality output
 */

// Enable error reporting for debugging but capture it
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Start output buffering to catch any errors
ob_start();

try {
    require_once __DIR__ . '/../config.php';
    require_once INCLUDES_DIR . '/Auth.php';
    
    // Check if TCPDF is available
    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'TCPDF not installed. Run: composer require tecnickcom/tcpdf']);
        exit;
    }
    
    require_once INCLUDES_DIR . '/PrintReadyGenerator.php';
    
} catch (Exception $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    error_log('print-ready config error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server configuration error']);
    exit;
}

// Clear any output buffer content (warnings, etc)
ob_end_clean();

header('Content-Type: application/json');

// Allow only authenticated print shops or super admins
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized - Please log in']);
    exit;
}

$role = Auth::getCurrentRole();
if (!in_array($role, ['print_shop', 'super_admin', 'company'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'layout';

try {
    switch ($action) {
        case 'layout':
            handleLayoutRequest();
            break;
            
        case 'detect':
            handleDetectRequest();
            break;
            
        case 'generate':
            handleGenerateRequest();
            break;
            
        case 'specs':
            handleSpecsRequest();
            break;
            
        case 'download':
            handleDownloadRequest();
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('print-ready error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred']);
}

/**
 * Get layout data for preview
 */
function handleLayoutRequest() {
    $paperSize = $_GET['paper'] ?? $_POST['paper'] ?? 'A4';
    $cardWidth = (float)($_GET['card_width'] ?? $_POST['card_width'] ?? 85);
    $cardHeight = (float)($_GET['card_height'] ?? $_POST['card_height'] ?? 55);
    $quantity = isset($_GET['quantity']) ? (int)$_GET['quantity'] : null;
    
    $generator = new PrintReadyGenerator([
        'paper_size' => $paperSize,
        'card_width' => $cardWidth,
        'card_height' => $cardHeight
    ]);
    
    $layoutData = $generator->generateLayoutData($quantity);
    $specs = $generator->getSpecifications();
    
    echo json_encode([
        'success' => true,
        'data' => $layoutData,
        'specs' => $specs
    ]);
}

/**
 * Get card size from template settings (for a specific order)
 */
function handleDetectRequest() {
    $orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
    
    // Card size presets (same as in template editor)
    $cardSizePresets = [
        'standard' => ['widthMm' => 89, 'heightMm' => 51, 'name' => 'US Standard (89×51mm)'],
        'eu' => ['widthMm' => 85, 'heightMm' => 55, 'name' => 'European (85×55mm)'],
        'japanese' => ['widthMm' => 91, 'heightMm' => 55, 'name' => 'Japanese (91×55mm)'],
        'square' => ['widthMm' => 63.5, 'heightMm' => 63.5, 'name' => 'Square (63.5×63.5mm)'],
        'mini' => ['widthMm' => 70, 'heightMm' => 28, 'name' => 'Mini/Slim (70×28mm)']
    ];
    
    // Default to EU standard
    $cardWidth = 85;
    $cardHeight = 55;
    $sizeName = 'European (85×55mm)';
    
    if ($orderId > 0) {
        $db = Database::getInstance();

        // Get the order to find the company
        $order = $db->fetchOne("SELECT company_id FROM print_orders WHERE id = ?", [$orderId]);

        // Verify ownership: user must belong to the order's company (or be super_admin)
        $userRole = Auth::getCurrentRole();
        $userCompanyId = $_SESSION['company_id'] ?? null;
        if ($order && $userRole !== 'super_admin' && $userCompanyId !== ($order['company_id'] ?? null)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }

        if ($order && !empty($order['company_id'])) {
            // Get active front template for this company
            $template = $db->fetchOne(
                "SELECT settings_json FROM templates WHERE company_id = ? AND side = 'front' AND is_active = 1 LIMIT 1",
                [$order['company_id']]
            );
            
            if ($template && !empty($template['settings_json'])) {
                $settings = json_decode($template['settings_json'], true);
                
                if (!empty($settings['cardSize'])) {
                    $sizeKey = $settings['cardSize'];
                    
                    if ($sizeKey === 'custom' && !empty($settings['customWidth']) && !empty($settings['customHeight'])) {
                        if (($settings['customUnit'] ?? 'in') === 'mm') {
                            $cardWidth = (float)$settings['customWidth'];
                            $cardHeight = (float)$settings['customHeight'];
                        } else {
                            $cardWidth = round((float)$settings['customWidth'] * 25.4, 1);
                            $cardHeight = round((float)$settings['customHeight'] * 25.4, 1);
                        }
                        $sizeName = "Custom ({$cardWidth}×{$cardHeight}mm)";
                    } elseif (isset($cardSizePresets[$sizeKey])) {
                        $cardWidth = $cardSizePresets[$sizeKey]['widthMm'];
                        $cardHeight = $cardSizePresets[$sizeKey]['heightMm'];
                        $sizeName = $cardSizePresets[$sizeKey]['name'];
                    }
                    
                    // Apply orientation if portrait
                    if (($settings['cardOrientation'] ?? 'landscape') === 'portrait') {
                        $temp = $cardWidth;
                        $cardWidth = $cardHeight;
                        $cardHeight = $temp;
                        $sizeName .= ' (Portrait)';
                    }
                }
            }
        }
    }
    
    // Calculate layout with detected size
    $generator = new PrintReadyGenerator([
        'card_width' => $cardWidth,
        'card_height' => $cardHeight
    ]);
    $layoutData = $generator->generateLayoutData();
    
    echo json_encode([
        'success' => true,
        'detected' => [
            'width' => $cardWidth,
            'height' => $cardHeight,
            'name' => $sizeName
        ],
        'layout' => $layoutData
    ]);
}

/**
 * Generate print-ready PDF using TCPDF
 */
function handleGenerateRequest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'POST required']);
        return;
    }
    
    $orderId = (int)($_POST['order_id'] ?? 0);
    $side = $_POST['side'] ?? 'both'; // 'front', 'back', or 'both'
    $paperSize = $_POST['paper'] ?? 'A4';
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : null;
    $scale = isset($_POST['scale']) ? (int)$_POST['scale'] : 100; // Scale percentage (90-110)
    
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
        return;
    }
    
    // Get order details
    $db = Database::getInstance();
    $order = $db->fetchOne("SELECT * FROM print_orders WHERE id = ?", [$orderId]);

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        return;
    }

    // Verify ownership: user must belong to the order's company (or be super_admin)
    $userRole = Auth::getCurrentRole();
    $userCompanyId = $_SESSION['company_id'] ?? null;
    if ($userRole !== 'super_admin' && $userCompanyId !== ($order['company_id'] ?? null)) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        return;
    }
    
    // Get card image paths - prefer HQ versions
    $frontUrl = $order['card_front_url'] ?? '';
    $backUrl = $order['card_back_url'] ?? '';
    
    // Try HQ versions first
    $frontPath = null;
    $backPath = null;
    
    if (!empty($frontUrl)) {
        $frontHq = str_replace('.png', '_hq.png', $frontUrl);
        $frontHqPath = BASE_DIR . '/' . ltrim($frontHq, '/');
        $frontPath = file_exists($frontHqPath) ? $frontHqPath : BASE_DIR . '/' . ltrim($frontUrl, '/');
    }
    
    if (!empty($backUrl)) {
        $backHq = str_replace('.png', '_hq.png', $backUrl);
        $backHqPath = BASE_DIR . '/' . ltrim($backHq, '/');
        $backPath = file_exists($backHqPath) ? $backHqPath : BASE_DIR . '/' . ltrim($backUrl, '/');
    }
    
    // Validate paths
    if ($side !== 'back' && (!$frontPath || !file_exists($frontPath))) {
        echo json_encode(['success' => false, 'error' => 'Front card image not found: ' . $frontPath]);
        return;
    }
    
    if ($side !== 'front' && (!$backPath || !file_exists($backPath))) {
        echo json_encode(['success' => false, 'error' => 'Back card image not found: ' . $backPath]);
        return;
    }
    
    // Get card size from template settings
    $cardWidth = 85;  // Default EU standard width in mm
    $cardHeight = 55; // Default EU standard height in mm
    
    // Card size presets (same as in template editor)
    $cardSizePresets = [
        'standard' => ['widthMm' => 89, 'heightMm' => 51],    // US Standard 3.5" × 2"
        'eu' => ['widthMm' => 85, 'heightMm' => 55],          // European
        'japanese' => ['widthMm' => 91, 'heightMm' => 55],    // Japanese
        'square' => ['widthMm' => 63.5, 'heightMm' => 63.5],  // Square 2.5" × 2.5"
        'mini' => ['widthMm' => 70, 'heightMm' => 28]         // Mini/Slim
    ];
    
    // Try to get template settings from the company's active template
    try {
        $companyId = $order['company_id'] ?? null;
        if ($companyId) {
            // Get active front template for this company
            $template = $db->fetchOne(
                "SELECT settings_json FROM templates WHERE company_id = ? AND side = 'front' AND is_active = 1 LIMIT 1",
                [$companyId]
            );
            
            if ($template && !empty($template['settings_json'])) {
                $settings = json_decode($template['settings_json'], true);
                
                if (!empty($settings['cardSize'])) {
                    $sizeKey = $settings['cardSize'];
                    
                    if ($sizeKey === 'custom' && !empty($settings['customWidth']) && !empty($settings['customHeight'])) {
                        // Custom size
                        if (($settings['customUnit'] ?? 'in') === 'mm') {
                            $cardWidth = (float)$settings['customWidth'];
                            $cardHeight = (float)$settings['customHeight'];
                        } else {
                            // Convert inches to mm
                            $cardWidth = (float)$settings['customWidth'] * 25.4;
                            $cardHeight = (float)$settings['customHeight'] * 25.4;
                        }
                    } elseif (isset($cardSizePresets[$sizeKey])) {
                        // Preset size
                        $cardWidth = $cardSizePresets[$sizeKey]['widthMm'];
                        $cardHeight = $cardSizePresets[$sizeKey]['heightMm'];
                    }
                    
                    // Apply orientation if portrait
                    if (($settings['cardOrientation'] ?? 'landscape') === 'portrait') {
                        $temp = $cardWidth;
                        $cardWidth = $cardHeight;
                        $cardHeight = $temp;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Use default size on error
    }
    
    // Create output directory
    $outputDir = BASE_DIR . '/data/print-sheets';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    // Generate unique filename
    $filename = sprintf('print-sheet-order-%d-%s-%s.pdf', 
                       $orderId, 
                       $paperSize, 
                       date('Ymd-His'));
    $outputPath = $outputDir . '/' . $filename;
    
    // Prefer the vector imposition when both sides are vector-source.
    $companyId = $order['company_id'] ?? null;
    $useVectorImposition = false;
    if ($companyId) {
        $frontTpl = $db->fetchOne(
            "SELECT has_vector_source FROM templates WHERE company_id = ? AND side='front' AND is_active=1 LIMIT 1",
            [$companyId]
        );
        $backTpl = $db->fetchOne(
            "SELECT has_vector_source FROM templates WHERE company_id = ? AND side='back' AND is_active=1 LIMIT 1",
            [$companyId]
        );
        $wantVector = is_array($frontTpl) && (int)($frontTpl['has_vector_source'] ?? 0) === 1
                   && is_array($backTpl)  && (int)($backTpl['has_vector_source']  ?? 0) === 1;

        if ($wantVector) {
            require_once INCLUDES_DIR . '/CardPDFRenderer.php';
            $employeeId = (string)($order['employee_id'] ?? '');
            $cardPdf = $employeeId !== '' ? CardPDFRenderer::render($employeeId) : ['success' => false];
            if (!empty($cardPdf['success'])) {
                $py = trim((string)@shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
                $rows = isset($_POST['rows']) ? (int)$_POST['rows'] : 5;
                $cols = isset($_POST['cols']) ? (int)$_POST['cols'] : 2;
                $vectorCmd = escapeshellcmd($py)
                           . ' ' . escapeshellarg(BASE_DIR . '/scripts/imposition-vector.py')
                           . ' --card '  . escapeshellarg($cardPdf['path'])
                           . ' --paper ' . escapeshellarg($paperSize)
                           . ' --rows '  . (int)$rows
                           . ' --cols '  . (int)$cols
                           . ' --out '   . escapeshellarg($outputPath)
                           . ' 2>&1';
                $vectorRc = 0;
                $vectorOut = [];
                exec($vectorCmd, $vectorOut, $vectorRc);
                if ($vectorRc === 0 && is_file($outputPath) && filesize($outputPath) > 1024) {
                    $useVectorImposition = true;
                } else {
                    error_log('print-ready vector imposition failed rc=' . $vectorRc . ' out=' . implode("\n", $vectorOut));
                }
            }
        }
    }

    if ($useVectorImposition) {
        $relativeUrl = 'data/print-sheets/' . $filename;
        echo json_encode([
            'success'         => true,
            'download_url'    => getBasePath() . $relativeUrl,
            'filename'        => $filename,
            'layout'          => ['vector' => true],
            'cards_per_sheet' => ($rows ?? 5) * ($cols ?? 2),
            'detected_size'   => [
                'width'  => $cardWidth,
                'height' => $cardHeight,
            ],
            'vector_imposition' => true,
        ]);
        return;
    }

    // Generate PDF via TCPDF (raster fallback)
    $generator = new PrintReadyGenerator([
        'paper_size' => $paperSize,
        'card_width' => $cardWidth,
        'card_height' => $cardHeight,
        'dpi' => 300,
        'scale' => $scale // Scale percentage for zoom
    ]);

    // Determine which images to include
    $frontImage = ($side === 'back') ? null : $frontPath;
    $backImage = ($side === 'front') ? null : $backPath;

    $result = $generator->generatePDF($frontImage, $backImage, $outputPath, $quantity);

    if ($result['success']) {
        // Return download URL
        $relativeUrl = 'data/print-sheets/' . $filename;
        echo json_encode([
            'success' => true,
            'download_url' => getBasePath() . $relativeUrl,
            'filename' => $filename,
            'layout' => $result['layout'],
            'cards_per_sheet' => $result['cards_per_sheet'],
            'detected_size' => [
                'width' => $cardWidth,
                'height' => $cardHeight
            ]
        ]);
    } else {
        echo json_encode($result);
    }
}

/**
 * Direct download of generated PDF
 */
function handleDownloadRequest() {
    $file = $_GET['file'] ?? '';

    if (empty($file) || strpos($file, '..') !== false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid file']);
        return;
    }

    $safeFile = basename($file);
    $filePath = BASE_DIR . '/data/print-sheets/' . $safeFile;

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'File not found']);
        return;
    }

    // Verify ownership: extract order ID from filename and check company
    if (preg_match('/print-sheet-order-(\d+)-/', $safeFile, $matches)) {
        $orderId = (int)$matches[1];
        $db = Database::getInstance();
        $order = $db->fetchOne("SELECT company_id FROM print_orders WHERE id = ?", [$orderId]);
        $userRole = Auth::getCurrentRole();
        $userCompanyId = $_SESSION['company_id'] ?? null;
        if ($order && $userRole !== 'super_admin' && $userCompanyId !== ($order['company_id'] ?? null)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            return;
        }
    }

    // Serve file for download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    
    readfile($filePath);
    exit;
}

/**
 * Get available paper and card sizes
 */
function handleSpecsRequest() {
    echo json_encode([
        'success' => true,
        'paper_sizes' => PrintReadyGenerator::getPaperSizes(),
        'card_sizes' => PrintReadyGenerator::getCardSizes()
    ]);
}
