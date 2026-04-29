<?php
/**
 * ProofSheet Class - Generate professional digital proof sheets
 * Creates PDF proof sheets with both card sides, checklist, and Cardify branding
 * Card dimensions are derived from template settings (not hardcoded)
 */
class ProofSheet {
    
    // Page dimensions (A4 landscape for better card display)
    const PAGE_WIDTH_MM = 297;
    const PAGE_HEIGHT_MM = 210;
    
    // Proof sheet margins
    const MARGIN_MM = 15;
    
    // Card size presets (matching template editor)
    private static $cardSizes = [
        'standard' => ['widthIn' => 3.5, 'heightIn' => 2],      // US Standard
        'eu' => ['widthIn' => 3.346, 'heightIn' => 2.165],      // European (85x55mm)
        'japanese' => ['widthIn' => 3.582, 'heightIn' => 2.165], // Japanese
        'square' => ['widthIn' => 2.5, 'heightIn' => 2.5],      // Square
        'mini' => ['widthIn' => 2.75, 'heightIn' => 1.1],       // Mini
        'custom' => ['widthIn' => 3.5, 'heightIn' => 2]         // Default for custom
    ];
    
    /**
     * Get card dimensions from template settings
     * 
     * @param array|null $templateSettings Template settings from designer
     * @return array ['width_mm' => float, 'height_mm' => float, 'aspect_ratio' => float]
     */
    public static function getCardDimensions($templateSettings = null) {
        // Default to standard US business card
        $widthIn = 3.5;
        $heightIn = 2;
        
        if ($templateSettings) {
            $settings = $templateSettings['settings'] ?? $templateSettings;
            $cardSize = $settings['cardSize'] ?? 'standard';
            $orientation = $settings['cardOrientation'] ?? 'landscape';
            
            // Get base dimensions from preset
            if (isset(self::$cardSizes[$cardSize])) {
                $widthIn = self::$cardSizes[$cardSize]['widthIn'];
                $heightIn = self::$cardSizes[$cardSize]['heightIn'];
            }
            
            // Handle custom size
            if ($cardSize === 'custom') {
                $customWidth = $settings['customWidth'] ?? null;
                $customHeight = $settings['customHeight'] ?? null;
                $customUnit = $settings['customUnit'] ?? 'in';
                
                if ($customWidth && $customHeight) {
                    if ($customUnit === 'mm') {
                        $widthIn = $customWidth / 25.4;
                        $heightIn = $customHeight / 25.4;
                    } else {
                        $widthIn = $customWidth;
                        $heightIn = $customHeight;
                    }
                }
            }
            
            // Apply orientation (swap for portrait)
            if ($orientation === 'portrait' && $cardSize !== 'square') {
                $temp = $widthIn;
                $widthIn = $heightIn;
                $heightIn = $temp;
            }
        }
        
        // Convert to mm
        $widthMm = $widthIn * 25.4;
        $heightMm = $heightIn * 25.4;
        
        return [
            'width_mm' => round($widthMm, 2),
            'height_mm' => round($heightMm, 2),
            'width_in' => $widthIn,
            'height_in' => $heightIn,
            'aspect_ratio' => $widthIn / $heightIn
        ];
    }
    
    /**
     * Generate a proof sheet PDF with both card sides
     * 
     * @param string $frontImagePath Path to front card image (PNG)
     * @param string $backImagePath Path to back card image (PNG)
     * @param array $employeeData Employee information
     * @param array $companyData Company information
     * @param array $options Additional options including 'template_settings'
     * @return string|false Path to generated PDF or false on failure
     */
    public static function generate($frontImagePath, $backImagePath, $employeeData, $companyData, $options = []) {
        // Require TCPDF or FPDF - we'll use a pure PHP approach
        $outputDir = $options['output_dir'] ?? (BASE_DIR . '/data/proofs');
        
        // Get card dimensions from template settings if provided
        $templateSettings = $options['template_settings'] ?? null;
        $cardDims = self::getCardDimensions($templateSettings);
        $options['card_dimensions'] = $cardDims;
        
        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        // Generate unique filename
        $timestamp = date('Ymd_His');
        $employeeSlug = preg_replace('/[^a-z0-9]/i', '_', $employeeData['name_en'] ?? 'employee');
        $filename = "proof_{$employeeSlug}_{$timestamp}.pdf";
        $outputPath = $outputDir . '/' . $filename;
        
        // Check if TCPDF is available
        $tcpdfPath = BASE_DIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (file_exists($tcpdfPath)) {
            return self::generateWithTCPDF($frontImagePath, $backImagePath, $employeeData, $companyData, $outputPath, $options);
        }
        
        // Fallback to creating HTML-based proof that can be converted to PDF
        return self::generateHTMLProof($frontImagePath, $backImagePath, $employeeData, $companyData, $outputPath, $options);
    }
    
    /**
     * Generate proof sheet using TCPDF (if available)
     */
    private static function generateWithTCPDF($frontImagePath, $backImagePath, $employeeData, $companyData, $outputPath, $options) {
        require_once BASE_DIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Cardify');
        $pdf->SetAuthor($companyData['name_en'] ?? 'Company');
        $pdf->SetTitle('Business Card Proof - ' . ($employeeData['name_en'] ?? ''));
        $pdf->SetSubject('Digital Proof Sheet');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins
        $pdf->SetMargins(self::MARGIN_MM, self::MARGIN_MM, self::MARGIN_MM);
        
        // Add a page
        $pdf->AddPage();
        
        // Add content
        self::addProofContent($pdf, $frontImagePath, $backImagePath, $employeeData, $companyData, $options);
        
        // Output PDF
        $pdf->Output($outputPath, 'F');
        
        return file_exists($outputPath) ? $outputPath : false;
    }
    
    /**
     * Add proof content to PDF
     */
    private static function addProofContent($pdf, $frontImagePath, $backImagePath, $employeeData, $companyData, $options) {
        $pageWidth = self::PAGE_WIDTH_MM - (2 * self::MARGIN_MM);
        $pageHeight = self::PAGE_HEIGHT_MM - (2 * self::MARGIN_MM);
        
        // Get card dimensions from options (set by generate method based on template settings)
        $cardDims = $options['card_dimensions'] ?? self::getCardDimensions();
        $cardWidthMm = $cardDims['width_mm'];
        $cardHeightMm = $cardDims['height_mm'];
        $aspectRatio = $cardDims['aspect_ratio'];
        
        // Title / Header
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(30, 64, 175); // Blue
        $pdf->Cell(0, 10, $siteName . ' - Digital Proof', 0, 1, 'L');
        
        // Company and date info
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell($pageWidth / 2, 6, 'Company: ' . ($companyData['name_en'] ?? ''), 0, 0, 'L');
        $pdf->Cell($pageWidth / 2, 6, 'Date: ' . date('F j, Y'), 0, 1, 'R');
        
        // Show card size info
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->SetTextColor(120, 120, 120);
        $sizeInfo = sprintf('Card Size: %.1fmm x %.1fmm (%.2f" x %.2f")', 
            $cardWidthMm, $cardHeightMm, $cardDims['width_in'], $cardDims['height_in']);
        $pdf->Cell(0, 4, $sizeInfo, 0, 1, 'L');
        
        $pdf->Ln(3);
        
        // Draw border around card area
        $cardAreaY = $pdf->GetY();
        $cardAreaHeight = 90;
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Rect(self::MARGIN_MM, $cardAreaY, $pageWidth, $cardAreaHeight);
        
        // Card images side by side - scale to fit while maintaining aspect ratio
        // Max display width per card is about 100mm, but we maintain the actual aspect ratio
        $maxDisplayWidth = 100;
        $cardDisplayWidth = min($maxDisplayWidth, $cardWidthMm * 1.2); // Scale up slightly for visibility
        $cardDisplayHeight = $cardDisplayWidth / $aspectRatio;
        
        // Ensure it fits in the card area
        if ($cardDisplayHeight > 70) {
            $cardDisplayHeight = 70;
            $cardDisplayWidth = $cardDisplayHeight * $aspectRatio;
        }
        
        $cardSpacing = 20;
        $totalCardsWidth = ($cardDisplayWidth * 2) + $cardSpacing;
        $startX = self::MARGIN_MM + ($pageWidth - $totalCardsWidth) / 2;
        
        // Front card
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($startX, $cardAreaY + 5);
        $pdf->Cell($cardDisplayWidth, 6, 'FRONT', 0, 0, 'C');
        
        if (file_exists($frontImagePath)) {
            $pdf->Image($frontImagePath, $startX, $cardAreaY + 12, $cardDisplayWidth, $cardDisplayHeight, '', '', '', false, 300);
        }
        
        // Back card
        $backX = $startX + $cardDisplayWidth + $cardSpacing;
        $pdf->SetXY($backX, $cardAreaY + 5);
        $pdf->Cell($cardDisplayWidth, 6, 'BACK', 0, 0, 'C');
        
        if (file_exists($backImagePath)) {
            $pdf->Image($backImagePath, $backX, $cardAreaY + 12, $cardDisplayWidth, $cardDisplayHeight, '', '', '', false, 300);
        }
        
        // Move to below card area
        $pdf->SetY($cardAreaY + $cardAreaHeight + 10);
        
        // Employee Details Section
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(30, 64, 175);
        $pdf->Cell(0, 8, 'Employee Details', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(50, 50, 50);
        
        $detailsLeft = [
            'Name (EN)' => $employeeData['name_en'] ?? '',
            'Name (AR)' => $employeeData['name_ar'] ?? '',
            'Position (EN)' => $employeeData['position_en'] ?? '',
            'Position (AR)' => $employeeData['position_ar'] ?? ''
        ];
        
        $detailsRight = [
            'Email' => $employeeData['email'] ?? '',
            'Phone' => $employeeData['phone'] ?? '',
            'Mobile' => $employeeData['mobile'] ?? '',
            'Department' => $employeeData['department_name'] ?? ''
        ];
        
        $colWidth = $pageWidth / 2;
        $startY = $pdf->GetY();
        
        // Left column
        foreach ($detailsLeft as $label => $value) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(35, 5, $label . ':', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($colWidth - 35, 5, $value, 0, 1, 'L');
        }
        
        // Right column
        $pdf->SetXY(self::MARGIN_MM + $colWidth, $startY);
        foreach ($detailsRight as $label => $value) {
            $pdf->SetXY(self::MARGIN_MM + $colWidth, $pdf->GetY());
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell(30, 5, $label . ':', 0, 0, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($colWidth - 30, 5, $value, 0, 1, 'L');
        }
        
        // Proof Checklist Section (at bottom)
        $checklistY = self::PAGE_HEIGHT_MM - 45;
        $pdf->SetY($checklistY);
        
        // PROOF label (vertical, red)
        $pdf->SetFont('helvetica', 'B', 24);
        $pdf->SetTextColor(220, 38, 38); // Red
        $pdf->StartTransform();
        $pdf->Rotate(90, self::MARGIN_MM + 8, $checklistY + 35);
        $pdf->Text(self::MARGIN_MM + 8, $checklistY + 35, 'PROOF');
        $pdf->StopTransform();
        
        // Checklist box
        $checklistX = self::MARGIN_MM + 25;
        $checklistWidth = $pageWidth - 25;
        
        $pdf->SetDrawColor(150, 150, 150);
        $pdf->Rect($checklistX, $checklistY, $checklistWidth, 30);
        
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(100, 100, 100);
        
        // PS# and Date fields
        $pdf->SetXY($checklistX + 5, $checklistY + 2);
        $pdf->Cell(40, 4, 'PS#: _____________', 0, 0, 'L');
        $pdf->Cell(50, 4, 'Date: ____/____/____', 0, 1, 'L');
        
        // Instructions
        $pdf->SetXY($checklistX + 5, $pdf->GetY());
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(220, 38, 38);
        $pdf->Cell(0, 3, 'All individuals must check and signoff on all items shown prior to advancing:', 0, 1, 'L');
        
        // Checklist items
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(50, 50, 50);
        
        $checkItems = [
            '1) PDF matches this proof',
            '2) Size is correct',
            '3) Art & copy are correct',
            '4) Bleeds are present if needed',
            '5) Zund lines present if needed',
            '6) PS & ticket details match',
            '7) Due date is correct'
        ];
        
        $itemX = $checklistX + 5;
        $itemY = $pdf->GetY() + 1;
        $itemWidth = 55;
        $checkboxX = $itemX + $itemWidth + 5;
        
        // Column headers
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetXY($checkboxX, $itemY - 4);
        $pdf->Cell(12, 3, 'Designer', 0, 0, 'C');
        $pdf->Cell(12, 3, 'CSR', 0, 0, 'C');
        $pdf->Cell(12, 3, 'Preflight', 0, 0, 'C');
        
        // Checklist items with checkboxes
        $pdf->SetFont('helvetica', '', 7);
        foreach ($checkItems as $i => $item) {
            $pdf->SetXY($itemX, $itemY + ($i * 3.5));
            $pdf->Cell($itemWidth, 3.5, $item, 0, 0, 'L');
            
            // Checkboxes
            $pdf->Rect($checkboxX + 4, $itemY + ($i * 3.5), 3, 3);
            $pdf->Rect($checkboxX + 16, $itemY + ($i * 3.5), 3, 3);
            $pdf->Rect($checkboxX + 28, $itemY + ($i * 3.5), 3, 3);
        }
        
        // Initials line
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->SetTextColor(220, 38, 38);
        $pdf->SetXY($itemX, $itemY + 27);
        $pdf->Cell(30, 3, 'Initials: _____ _____ _____', 0, 0, 'L');
        
        // Footer with Cardify branding
        $pdf->SetY(self::PAGE_HEIGHT_MM - self::MARGIN_MM + 2);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell(0, 5, 'Generated by ' . $siteName . ' - Business Card Management Platform | ' . date('Y-m-d H:i:s'), 0, 0, 'C');
    }
    
    /**
     * Generate HTML-based proof sheet (fallback when TCPDF not available)
     * Can be converted to PDF using wkhtmltopdf or browser print
     */
    private static function generateHTMLProof($frontImagePath, $backImagePath, $employeeData, $companyData, $outputPath, $options) {
        $siteName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
        
        // Convert images to base64 for embedding
        $frontBase64 = self::imageToBase64($frontImagePath);
        $backBase64 = self::imageToBase64($backImagePath);
        
        // Get card dimensions from options
        $cardDims = $options['card_dimensions'] ?? self::getCardDimensions();
        
        $html = self::generateProofHTML($frontBase64, $backBase64, $employeeData, $companyData, $siteName, $cardDims);
        
        // Save HTML version
        $htmlPath = str_replace('.pdf', '.html', $outputPath);
        file_put_contents($htmlPath, $html);
        
        // Try to convert to PDF using wkhtmltopdf if available
        $wkhtmltopdf = '/usr/local/bin/wkhtmltopdf';
        if (file_exists($wkhtmltopdf) || is_executable('wkhtmltopdf')) {
            $cmd = (file_exists($wkhtmltopdf) ? $wkhtmltopdf : 'wkhtmltopdf') . 
                   ' --orientation Landscape --page-size A4 ' .
                   escapeshellarg($htmlPath) . ' ' . escapeshellarg($outputPath) . ' 2>&1';
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputPath)) {
                unlink($htmlPath); // Remove HTML file
                return $outputPath;
            }
        }
        
        // Return HTML path if PDF conversion not available
        return $htmlPath;
    }
    
    /**
     * Generate the HTML content for proof sheet
     */
    /**
     * Generate the HTML content for proof sheet
     * 
     * @param string $frontBase64 Base64 encoded front card image
     * @param string $backBase64 Base64 encoded back card image
     * @param array $employeeData Employee information
     * @param array $companyData Company information
     * @param string $siteName Site/brand name
     * @param array|null $cardDims Card dimensions from template settings
     */
    public static function generateProofHTML($frontBase64, $backBase64, $employeeData, $companyData, $siteName = 'Cardify', $cardDims = null) {
        $date = date('F j, Y');
        $datetime = date('Y-m-d H:i:s');
        
        // Get card dimensions
        if (!$cardDims) {
            $cardDims = self::getCardDimensions();
        }
        
        // Calculate display size while maintaining aspect ratio
        $aspectRatio = $cardDims['aspect_ratio'];
        $maxWidth = 260; // Max pixel width for display
        $displayWidth = $maxWidth;
        $displayHeight = round($displayWidth / $aspectRatio);
        
        // Card size info string
        $cardSizeInfo = sprintf('%.1fmm × %.1fmm (%.2f" × %.2f")', 
            $cardDims['width_mm'], $cardDims['height_mm'], 
            $cardDims['width_in'], $cardDims['height_in']);
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Proof - {$employeeData['name_en']}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        @page { size: A4 landscape; margin: 10mm; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #fff;
            color: #333;
            padding: 15mm;
        }
        .proof-sheet { 
            width: 100%; 
            max-width: 267mm;
            margin: 0 auto;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #1e40af;
        }
        .logo { 
            font-size: 20px; 
            font-weight: bold; 
            color: #1e40af;
        }
        .date { 
            color: #666; 
            font-size: 12px;
        }
        .card-area {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            background: #fafafa;
        }
        .cards-container {
            display: flex;
            justify-content: center;
            gap: 30px;
            align-items: flex-start;
        }
        .card-wrapper {
            text-align: center;
        }
        .card-label {
            font-weight: 600;
            font-size: 12px;
            color: #333;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .card-image {
            max-width: {$displayWidth}px;
            height: auto;
            aspect-ratio: {$aspectRatio};
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            object-fit: contain;
        }
        .card-size-info {
            font-size: 10px;
            color: #888;
            margin-top: 5px;
        }
        .details-section {
            margin: 15px 0;
        }
        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #e5e7eb;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 5px 30px;
        }
        .detail-row {
            display: flex;
            font-size: 11px;
            padding: 3px 0;
        }
        .detail-label {
            font-weight: 600;
            width: 100px;
            color: #555;
        }
        .detail-value {
            color: #333;
        }
        .proof-footer {
            display: flex;
            margin-top: 15px;
            border: 1px solid #ccc;
            border-radius: 6px;
            overflow: hidden;
        }
        .proof-label {
            background: #dc2626;
            color: white;
            writing-mode: vertical-rl;
            text-orientation: mixed;
            font-weight: bold;
            font-size: 24px;
            padding: 10px 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .proof-checklist {
            flex: 1;
            padding: 10px 15px;
        }
        .proof-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .proof-fields {
            font-size: 10px;
        }
        .proof-instruction {
            font-size: 9px;
            color: #dc2626;
            font-style: italic;
            margin-bottom: 8px;
        }
        .checklist-table {
            font-size: 9px;
            width: 100%;
        }
        .checklist-table th {
            font-weight: normal;
            font-style: italic;
            padding: 2px 5px;
        }
        .checklist-table td {
            padding: 2px 5px;
        }
        .checkbox {
            display: inline-block;
            width: 10px;
            height: 10px;
            border: 1px solid #666;
            margin: 0 2px;
        }
        .initials {
            font-size: 9px;
            color: #dc2626;
            font-style: italic;
            margin-top: 8px;
        }
        .branding-footer {
            text-align: center;
            font-size: 9px;
            color: #999;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        @media print {
            body { padding: 0; }
            .proof-sheet { max-width: none; }
        }
    </style>
</head>
<body>
    <div class="proof-sheet">
        <div class="header">
            <div class="logo">{$siteName} - Digital Proof</div>
            <div class="date">Company: {$companyData['name_en']} | Date: {$date}</div>
        </div>
        <div class="card-size-info" style="margin-bottom: 10px;">Card Size: {$cardSizeInfo}</div>
        
        <div class="card-area">
            <div class="cards-container">
                <div class="card-wrapper">
                    <div class="card-label">Front</div>
                    <img src="{$frontBase64}" alt="Front Card" class="card-image">
                </div>
                <div class="card-wrapper">
                    <div class="card-label">Back</div>
                    <img src="{$backBase64}" alt="Back Card" class="card-image">
                </div>
            </div>
        </div>
        
        <div class="details-section">
            <div class="section-title">Employee Details</div>
            <div class="details-grid">
                <div class="detail-row">
                    <span class="detail-label">Name (EN):</span>
                    <span class="detail-value">{$employeeData['name_en']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Email:</span>
                    <span class="detail-value">{$employeeData['email']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Name (AR):</span>
                    <span class="detail-value">{$employeeData['name_ar']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value">{$employeeData['phone']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Position (EN):</span>
                    <span class="detail-value">{$employeeData['position_en']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Mobile:</span>
                    <span class="detail-value">{$employeeData['mobile']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Position (AR):</span>
                    <span class="detail-value">{$employeeData['position_ar']}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Department:</span>
                    <span class="detail-value">{$employeeData['department_name']}</span>
                </div>
            </div>
        </div>
        
        <div class="proof-footer">
            <div class="proof-label">PROOF</div>
            <div class="proof-checklist">
                <div class="proof-header">
                    <div class="proof-fields">PS#: _____________ &nbsp;&nbsp;&nbsp; Date: ____/____/____</div>
                </div>
                <div class="proof-instruction">All individuals must check and signoff on all items shown prior to advancing:</div>
                <table class="checklist-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Designer</th>
                            <th>CSR</th>
                            <th>Preflight</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1) PDF matches this proof</td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                        </tr>
                        <tr>
                            <td>2) Size is correct</td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                        </tr>
                        <tr>
                            <td>3) Art & copy are correct</td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                        </tr>
                        <tr>
                            <td>4) Bleeds are present if needed</td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                        </tr>
                        <tr>
                            <td>5) Zund lines present if needed</td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                        </tr>
                        <tr>
                            <td>6) PS & ticket details match</td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                        </tr>
                        <tr>
                            <td>7) Due date is correct</td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                            <td><span class="checkbox"></span></td>
                        </tr>
                    </tbody>
                </table>
                <div class="initials">Initials: _____ _____ _____</div>
            </div>
        </div>
        
        <div class="branding-footer">
            Generated by {$siteName} - Business Card Management Platform | {$datetime}
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Convert image file to base64 data URI
     */
    public static function imageToBase64($imagePath) {
        if (!file_exists($imagePath)) {
            return '';
        }
        
        $imageData = file_get_contents($imagePath);
        $mimeType = mime_content_type($imagePath) ?: 'image/png';
        
        return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
    }
    
    /**
     * Generate combined PDF with both card sides on a single page
     * 
     * @param string $frontImagePath Path to front card image
     * @param string $backImagePath Path to back card image
     * @param array $options Options (output_path, etc.)
     * @return string|false Path to generated PDF or false
     */
    public static function generateCombinedCardPDF($frontImagePath, $backImagePath, $options = []) {
        $outputDir = $options['output_dir'] ?? (BASE_DIR . '/data/cards/combined');
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $filename = $options['filename'] ?? ('combined_' . date('Ymd_His') . '.pdf');
        $outputPath = $outputDir . '/' . $filename;
        
        // Generate HTML for combined cards
        $frontBase64 = self::imageToBase64($frontImagePath);
        $backBase64 = self::imageToBase64($backImagePath);
        
        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Business Cards</title>
    <style>
        @page { size: 178mm 51mm; margin: 0; }
        body { margin: 0; padding: 0; }
        .cards { display: flex; }
        .card { width: 89mm; height: 51mm; }
        img { width: 100%; height: 100%; object-fit: contain; }
    </style>
</head>
<body>
    <div class="cards">
        <div class="card"><img src="{$frontBase64}"></div>
        <div class="card"><img src="{$backBase64}"></div>
    </div>
</body>
</html>
HTML;
        
        // Try wkhtmltopdf
        $htmlPath = str_replace('.pdf', '.html', $outputPath);
        file_put_contents($htmlPath, $html);
        
        $wkhtmltopdf = '/usr/local/bin/wkhtmltopdf';
        if (file_exists($wkhtmltopdf) || is_executable('wkhtmltopdf')) {
            $cmd = (file_exists($wkhtmltopdf) ? $wkhtmltopdf : 'wkhtmltopdf') . 
                   ' --page-width 178mm --page-height 51mm --margin-top 0 --margin-bottom 0 --margin-left 0 --margin-right 0 ' .
                   escapeshellarg($htmlPath) . ' ' . escapeshellarg($outputPath) . ' 2>&1';
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputPath)) {
                @unlink($htmlPath);
                return $outputPath;
            }
        }
        
        // Return HTML if PDF conversion not available
        return $htmlPath;
    }
    
    /**
     * Get quality multiplier based on plan
     * Free users get 70 DPI (1x), paid users get full quality (4x)
     * 
     * @param string $companyId Company ID
     * @return int Multiplier for image quality
     */
    public static function getQualityMultiplier($companyId) {
        $billing = new Billing();
        
        // Retained DPI tier gate from the subscription model.
        if ($billing->hasActiveSubscription($companyId)) {
            return 4; // ~400 DPI for companies with an active billing record
        }
        
        // Check plan type
        $db = Database::getInstance();
        $company = $db->fetchOne(
            "SELECT plan FROM companies WHERE id = :id",
            ['id' => $companyId]
        );
        
        $plan = $company['plan'] ?? 'free';
        
        if ($plan !== 'free') {
            return 4; // ~400 DPI for any non-free plan
        }
        
        // Free users get lower quality
        return 1; // ~100 DPI for free users (approximately 70 DPI when considering base canvas)
    }
    
    /**
     * Check if QR tracking is enabled for company
     * 
     * @param string $companyId Company ID
     * @return bool Whether QR tracking is enabled
     */
    public static function isQRTrackingEnabled($companyId) {
        $billing = new Billing();
        
        // Paid users get QR tracking
        if ($billing->hasActiveSubscription($companyId)) {
            return true;
        }
        
        // Check plan type
        $db = Database::getInstance();
        $company = $db->fetchOne(
            "SELECT plan FROM companies WHERE id = :id",
            ['id' => $companyId]
        );
        
        $plan = $company['plan'] ?? 'free';
        
        // Free users don't get QR tracking analytics
        return $plan !== 'free';
    }
}
