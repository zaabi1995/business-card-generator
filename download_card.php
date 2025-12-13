<?php
/**
 * Download card handler - serves PNG or generates PDF
 */
require_once __DIR__ . '/config.php';

$companySlug = $_GET['company'] ?? null;
if ($companySlug && isMultiTenantEnabled()) {
    $company = findCompanyBySlug($companySlug);
    if ($company) {
        setCompanyContext($company);
    }
}

$companyId = getCurrentCompanyId();
$cardsDir = $companyId ? getCompanyCardsDir($companyId) : CARDS_DIR;

$format = $_GET['format'] ?? 'png';
$side = $_GET['side'] ?? 'front';
$front = $_GET['front'] ?? '';
$back = $_GET['back'] ?? '';

if ($format === 'pdf') {
    // Check if TCPDF is available
    $tcpdfPath = BASE_DIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
    
    if (!file_exists($tcpdfPath)) {
        die('PDF generation requires TCPDF. Install with: composer require tecnickcom/tcpdf');
    }
    
    require_once $tcpdfPath;
    
    // Create PDF
    $pdf = new TCPDF('L', 'mm', array(89, 51), true, 'UTF-8', false); // Business card size
    
    $pdf->SetCreator(SITE_NAME);
    $pdf->SetAuthor(SITE_NAME);
    $pdf->SetTitle('Business Card');
    
    $pdf->SetMargins(0, 0, 0);
    $pdf->SetAutoPageBreak(false, 0);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Front page
    if ($front) {
        $frontPath = $cardsDir . '/' . basename($front);
        if (file_exists($frontPath)) {
            $pdf->AddPage();
            $pdf->Image($frontPath, 0, 0, 89, 51, '', '', '', false, 300, '', false, false, 0);
        }
    }
    
    // Back page
    if ($back) {
        $backPath = $cardsDir . '/' . basename($back);
        if (file_exists($backPath)) {
            $pdf->AddPage();
            $pdf->Image($backPath, 0, 0, 89, 51, '', '', '', false, 300, '', false, false, 0);
        }
    }
    
    // Output PDF
    $pdf->Output('business_card.pdf', 'D');
    exit;
}

// Default: serve PNG
$filename = $side === 'back' ? $back : $front;
if (empty($filename)) {
    http_response_code(404);
    die('File not found');
}

$filepath = $cardsDir . '/' . basename($filename);
if (!file_exists($filepath)) {
    http_response_code(404);
    die('File not found');
}

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="business_card_' . $side . '.png"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);

