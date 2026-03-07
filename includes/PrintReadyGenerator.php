<?php
/**
 * PrintReadyGenerator - Generates print-ready sheets with cutting marks using TCPDF
 * 
 * Creates A4/Letter sheets with multiple business cards arranged optimally
 * with professional cutting marks for print shops.
 */

// Load TCPDF
require_once dirname(__DIR__) . '/vendor/autoload.php';

class PrintReadyGenerator {
    // Paper sizes in mm
    const PAPER_SIZES = [
        'A4' => ['width' => 210, 'height' => 297],
        'A3' => ['width' => 297, 'height' => 420],
        'Letter' => ['width' => 215.9, 'height' => 279.4],
        'Legal' => ['width' => 215.9, 'height' => 355.6]
    ];
    
    // Common business card sizes in mm
    const CARD_SIZES = [
        'eu_standard' => ['width' => 85, 'height' => 55, 'name' => 'EU Standard (85×55mm)'],
        'us_standard' => ['width' => 88.9, 'height' => 50.8, 'name' => 'US Standard (3.5×2")'],
        'jp_standard' => ['width' => 91, 'height' => 55, 'name' => 'Japan Standard (91×55mm)'],
        'square' => ['width' => 65, 'height' => 65, 'name' => 'Square (65×65mm)'],
        'mini' => ['width' => 70, 'height' => 28, 'name' => 'Mini (70×28mm)']
    ];
    
    // Margin and bleed settings (mm)
    const DEFAULT_MARGIN = 5;       // Page margin
    const BLEED = 2;                // Image extends 2mm beyond trim line on all sides
    const CARD_GAP = 3;             // Gap between cards
    
    private $paperSize = 'A4';
    private $cardWidth = 85;        // mm
    private $cardHeight = 55;       // mm
    private $dpi = 300;             // Output DPI
    private $scale = 100;           // Scale percentage (90-110)
    private $includeBleed = true;
    private $includeCropMarks = true;
    private $includeColorBars = false;
    private $cropMarkColor = [0, 0, 0]; // Black
    private $registrationMarkSize = 3;  // mm
    
    public function __construct($options = []) {
        if (isset($options['paper_size'])) {
            $this->setPaperSize($options['paper_size']);
        }
        if (isset($options['card_width'])) {
            $this->cardWidth = (float)$options['card_width'];
        }
        if (isset($options['card_height'])) {
            $this->cardHeight = (float)$options['card_height'];
        }
        if (isset($options['dpi'])) {
            $this->dpi = (int)$options['dpi'];
        }
        if (isset($options['scale'])) {
            $this->scale = max(90, min(110, (int)$options['scale'])); // Clamp to 90-110
        }
        if (isset($options['include_bleed'])) {
            $this->includeBleed = (bool)$options['include_bleed'];
        }
        if (isset($options['include_crop_marks'])) {
            $this->includeCropMarks = (bool)$options['include_crop_marks'];
        }
    }
    
    /**
     * Set paper size
     */
    public function setPaperSize($size) {
        if (isset(self::PAPER_SIZES[$size])) {
            $this->paperSize = $size;
        }
    }
    
    /**
     * Set card dimensions (mm)
     */
    public function setCardSize($width, $height) {
        $this->cardWidth = (float)$width;
        $this->cardHeight = (float)$height;
    }
    
    /**
     * Auto-detect card size from image dimensions
     * Business cards have standard sizes - we match aspect ratio to find the best fit
     */
    public function detectCardSizeFromImage($imagePath) {
        if (!file_exists($imagePath)) {
            // Default to EU standard
            $this->cardWidth = 85;
            $this->cardHeight = 55;
            return self::CARD_SIZES['eu_standard'];
        }
        
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            // Default to EU standard
            $this->cardWidth = 85;
            $this->cardHeight = 55;
            return self::CARD_SIZES['eu_standard'];
        }
        
        $pixelWidth = $imageInfo[0];
        $pixelHeight = $imageInfo[1];
        
        // Calculate aspect ratio of the image
        $imageAspect = $pixelWidth / $pixelHeight;
        
        // Find the closest matching standard card size by aspect ratio
        $bestMatch = null;
        $bestDiff = PHP_FLOAT_MAX;
        
        foreach (self::CARD_SIZES as $key => $size) {
            $sizeAspect = $size['width'] / $size['height'];
            $diff = abs($imageAspect - $sizeAspect);
            
            // Also check rotated aspect (height/width)
            $sizeAspectRot = $size['height'] / $size['width'];
            $diffRot = abs($imageAspect - $sizeAspectRot);
            
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestMatch = $size;
            }
            if ($diffRot < $bestDiff) {
                $bestDiff = $diffRot;
                // If rotated matches better, swap dimensions
                $bestMatch = [
                    'width' => $size['height'],
                    'height' => $size['width'],
                    'name' => $size['name'] . ' (Portrait)'
                ];
            }
        }
        
        // If aspect ratio is within 20% of a standard size, use it
        if ($bestMatch && $bestDiff < 0.2) {
            $this->cardWidth = $bestMatch['width'];
            $this->cardHeight = $bestMatch['height'];
            return $bestMatch;
        }
        
        // Default to EU standard (most common)
        $this->cardWidth = 85;
        $this->cardHeight = 55;
        return self::CARD_SIZES['eu_standard'];
    }
    
    /**
     * Calculate optimal layout
     */
    public function calculateLayout() {
        $paper = self::PAPER_SIZES[$this->paperSize];
        
        $availWidth = $paper['width'] - (2 * self::DEFAULT_MARGIN);
        $availHeight = $paper['height'] - (2 * self::DEFAULT_MARGIN);
        
        $cardSlotWidth = $this->cardWidth + self::CARD_GAP;
        $cardSlotHeight = $this->cardHeight + self::CARD_GAP;
        
        // Portrait orientation
        $colsPortrait = floor($availWidth / $cardSlotWidth);
        $rowsPortrait = floor($availHeight / $cardSlotHeight);
        $totalPortrait = $colsPortrait * $rowsPortrait;
        
        // Landscape cards on portrait page
        $cardSlotWidthRot = $this->cardHeight + self::CARD_GAP;
        $cardSlotHeightRot = $this->cardWidth + self::CARD_GAP;
        
        $colsLandscape = floor($availWidth / $cardSlotWidthRot);
        $rowsLandscape = floor($availHeight / $cardSlotHeightRot);
        $totalLandscape = $colsLandscape * $rowsLandscape;
        
        if ($totalLandscape > $totalPortrait) {
            return [
                'cols' => $colsLandscape,
                'rows' => $rowsLandscape,
                'total' => $totalLandscape,
                'rotated' => true,
                'card_width' => $this->cardHeight,
                'card_height' => $this->cardWidth
            ];
        }
        
        return [
            'cols' => $colsPortrait,
            'rows' => $rowsPortrait,
            'total' => $totalPortrait,
            'rotated' => false,
            'card_width' => $this->cardWidth,
            'card_height' => $this->cardHeight
        ];
    }
    
    /**
     * Get print sheet specifications
     */
    public function getSpecifications() {
        $paper = self::PAPER_SIZES[$this->paperSize];
        $layout = $this->calculateLayout();
        
        return [
            'paper' => [
                'size' => $this->paperSize,
                'width_mm' => $paper['width'],
                'height_mm' => $paper['height']
            ],
            'card' => [
                'width_mm' => $this->cardWidth,
                'height_mm' => $this->cardHeight
            ],
            'layout' => $layout,
            'settings' => [
                'dpi' => $this->dpi,
                'margin_mm' => self::DEFAULT_MARGIN,
                'bleed_mm' => self::BLEED,
                'gap_mm' => self::CARD_GAP,
                'crop_mark_length_mm' => 4, // Outside: 4mm, Inside: 1mm
                'scale' => $this->scale
            ]
        ];
    }
    
    /**
     * Generate layout data for client-side preview
     */
    public function generateLayoutData($quantity = null) {
        $paper = self::PAPER_SIZES[$this->paperSize];
        $layout = $this->calculateLayout();
        
        $actualCardWidth = $layout['rotated'] ? $this->cardHeight : $this->cardWidth;
        $actualCardHeight = $layout['rotated'] ? $this->cardWidth : $this->cardHeight;
        
        $totalCardsWidth = ($layout['cols'] * $actualCardWidth) + (($layout['cols'] - 1) * self::CARD_GAP);
        $totalCardsHeight = ($layout['rows'] * $actualCardHeight) + (($layout['rows'] - 1) * self::CARD_GAP);
        
        $startX = ($paper['width'] - $totalCardsWidth) / 2;
        $startY = ($paper['height'] - $totalCardsHeight) / 2;
        
        $positions = [];
        $cardIndex = 0;
        $totalCards = $quantity ?? $layout['total'];
        
        for ($row = 0; $row < $layout['rows'] && $cardIndex < $totalCards; $row++) {
            for ($col = 0; $col < $layout['cols'] && $cardIndex < $totalCards; $col++) {
                $x = $startX + ($col * ($actualCardWidth + self::CARD_GAP));
                $y = $startY + ($row * ($actualCardHeight + self::CARD_GAP));
                
                $positions[] = [
                    'x' => $x,
                    'y' => $y,
                    'width' => $actualCardWidth,
                    'height' => $actualCardHeight,
                    'rotated' => $layout['rotated']
                ];
                
                $cardIndex++;
            }
        }
        
        $cropMarks = [];
        if ($this->includeCropMarks) {
            foreach ($positions as $pos) {
                $cropMarks = array_merge($cropMarks, $this->generateCropMarksForCard($pos));
            }
        }
        
        return [
            'paper' => [
                'size' => $this->paperSize,
                'width_mm' => $paper['width'],
                'height_mm' => $paper['height'],
                'width_pt' => $this->mmToPoints($paper['width']),
                'height_pt' => $this->mmToPoints($paper['height'])
            ],
            'layout' => $layout,
            'positions' => $positions,
            'crop_marks' => $cropMarks,
            'total_sheets' => ceil($totalCards / $layout['total'])
        ];
    }
    
    /**
     * Generate crop marks for a single card
     * Longer arms outside (4mm), shorter inside (1mm)
     */
    private function generateCropMarksForCard($position) {
        $x = $position['x'];
        $y = $position['y'];
        $w = $position['width'];
        $h = $position['height'];
        
        $outLen = 4; // mm outside
        $inLen = 1;  // mm inside (on bleed)
        
        return [
            // Top-left corner
            ['x1' => $x - $outLen, 'y1' => $y, 'x2' => $x + $inLen, 'y2' => $y],
            ['x1' => $x, 'y1' => $y - $outLen, 'x2' => $x, 'y2' => $y + $inLen],
            // Top-right corner
            ['x1' => $x + $w - $inLen, 'y1' => $y, 'x2' => $x + $w + $outLen, 'y2' => $y],
            ['x1' => $x + $w, 'y1' => $y - $outLen, 'x2' => $x + $w, 'y2' => $y + $inLen],
            // Bottom-left corner
            ['x1' => $x - $outLen, 'y1' => $y + $h, 'x2' => $x + $inLen, 'y2' => $y + $h],
            ['x1' => $x, 'y1' => $y + $h - $inLen, 'x2' => $x, 'y2' => $y + $h + $outLen],
            // Bottom-right corner
            ['x1' => $x + $w - $inLen, 'y1' => $y + $h, 'x2' => $x + $w + $outLen, 'y2' => $y + $h],
            ['x1' => $x + $w, 'y1' => $y + $h - $inLen, 'x2' => $x + $w, 'y2' => $y + $h + $outLen]
        ];
    }
    
    /**
     * Generate print-ready PDF using TCPDF
     */
    public function generatePDF($frontImagePath, $backImagePath, $outputPath, $quantity = null) {
        $paper = self::PAPER_SIZES[$this->paperSize];
        $layout = $this->calculateLayout();
        
        // Create TCPDF instance
        $pdf = new TCPDF('P', 'mm', [$paper['width'], $paper['height']], true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Cardify Print Shop');
        $pdf->SetAuthor('Cardify');
        $pdf->SetTitle('Print-Ready Business Cards');
        $pdf->SetSubject('Business Card Print Sheet');
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins to 0 (we handle margins ourselves)
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        
        // Calculate positions
        $actualCardWidth = $layout['rotated'] ? $this->cardHeight : $this->cardWidth;
        $actualCardHeight = $layout['rotated'] ? $this->cardWidth : $this->cardHeight;
        
        $totalCardsWidth = ($layout['cols'] * $actualCardWidth) + (($layout['cols'] - 1) * self::CARD_GAP);
        $totalCardsHeight = ($layout['rows'] * $actualCardHeight) + (($layout['rows'] - 1) * self::CARD_GAP);
        
        $startX = ($paper['width'] - $totalCardsWidth) / 2;
        $startY = ($paper['height'] - $totalCardsHeight) / 2;
        
        // Generate front page
        if ($frontImagePath && file_exists($frontImagePath)) {
            $pdf->AddPage();
            $this->addPrintSheetPage($pdf, $frontImagePath, $layout, $startX, $startY, 
                                     $actualCardWidth, $actualCardHeight, $paper, 'FRONT', $quantity);
        }
        
        // Generate back page
        if ($backImagePath && file_exists($backImagePath)) {
            $pdf->AddPage();
            $this->addPrintSheetPage($pdf, $backImagePath, $layout, $startX, $startY, 
                                     $actualCardWidth, $actualCardHeight, $paper, 'BACK', $quantity);
        }
        
        // Save PDF
        $pdf->Output($outputPath, 'F');
        
        return [
            'success' => true,
            'path' => $outputPath,
            'layout' => $layout,
            'cards_per_sheet' => $layout['total']
        ];
    }
    
    /**
     * Add a print sheet page to the PDF
     */
    private function addPrintSheetPage($pdf, $imagePath, $layout, $startX, $startY, 
                                       $cardWidth, $cardHeight, $paper, $side, $quantity = null) {
        $totalCards = $quantity ? min($quantity, $layout['total']) : $layout['total'];
        $cardIndex = 0;
        
        // Set line width for crop marks
        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor($this->cropMarkColor[0], $this->cropMarkColor[1], $this->cropMarkColor[2]);
        
        // Scale factor: 100% = exact size, >100% = zoom in (bleed), <100% = zoom out
        $scaleFactor = $this->scale / 100;
        
        for ($row = 0; $row < $layout['rows'] && $cardIndex < $totalCards; $row++) {
            for ($col = 0; $col < $layout['cols'] && $cardIndex < $totalCards; $col++) {
                // Trim position (where crop marks go - this is the actual card size)
                $x = $startX + ($col * ($cardWidth + self::CARD_GAP));
                $y = $startY + ($row * ($cardHeight + self::CARD_GAP));
                
                // At 100%: image = card size, crop marks exactly at edge
                // At >100%: image larger (zoomed in), extends past crop marks
                // At <100%: image smaller (zoomed out), gap between image and crop marks
                $imgW = $cardWidth * $scaleFactor;
                $imgH = $cardHeight * $scaleFactor;
                
                // Center the scaled image on the card position
                $imgX = $x + ($cardWidth - $imgW) / 2;
                $imgY = $y + ($cardHeight - $imgH) / 2;
                
                // Add card image
                if ($layout['rotated']) {
                    $this->addRotatedImage($pdf, $imagePath, $imgX, $imgY, $imgW, $imgH);
                } else {
                    $pdf->Image($imagePath, $imgX, $imgY, $imgW, $imgH, '', '', '', true, 300);
                }
                
                // Draw crop marks at trim line (exact card size)
                if ($this->includeCropMarks) {
                    $this->drawCropMarks($pdf, $x, $y, $cardWidth, $cardHeight);
                }
                
                $cardIndex++;
            }
        }
        
        // Add page info text
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(128, 128, 128);
        $scaleInfo = $this->scale != 100 ? " - Image: {$this->scale}%" : "";
        $info = sprintf('%s - %d×%dmm cards - %d/sheet%s - Print PDF at 100%%', 
                       $side, (int)$this->cardWidth, (int)$this->cardHeight, $layout['total'], $scaleInfo);
        $pdf->Text(self::DEFAULT_MARGIN, $paper['height'] - 5, $info);
        
        // Add registration marks in corners
        $this->drawRegistrationMarks($pdf, $paper);
    }
    
    /**
     * Add rotated image to PDF
     */
    private function addRotatedImage($pdf, $imagePath, $x, $y, $width, $height) {
        // TCPDF doesn't easily support image rotation, so we'll use GD to rotate first
        $imageInfo = getimagesize($imagePath);
        $sourceImage = $this->loadImage($imagePath);
        
        if ($sourceImage) {
            // Rotate 90 degrees
            $rotated = imagerotate($sourceImage, -90, 0);
            
            // Save to temp file
            $tempFile = sys_get_temp_dir() . '/rotated_' . uniqid() . '.png';
            imagepng($rotated, $tempFile);
            imagedestroy($sourceImage);
            imagedestroy($rotated);
            
            // Add to PDF
            $pdf->Image($tempFile, $x, $y, $width, $height, 'PNG', '', '', true, 300);
            
            // Clean up
            @unlink($tempFile);
        } else {
            // Fallback: add without rotation
            $pdf->Image($imagePath, $x, $y, $width, $height, '', '', '', true, 300);
        }
    }
    
    /**
     * Draw crop marks at corners of card
     * Longer arms on outside (4mm), shorter inside (1mm)
     */
    private function drawCropMarks($pdf, $x, $y, $w, $h) {
        $outLen = 4;       // Length outside the card (mm)
        $inLen = 1;        // Length inside the card (mm) - on the bleed
        $markWidth = 0.15; // Thin line
        
        $pdf->SetLineWidth($markWidth);
        
        // Top-left corner
        $pdf->Line($x - $outLen, $y, $x + $inLen, $y);  // Horizontal
        $pdf->Line($x, $y - $outLen, $x, $y + $inLen);  // Vertical
        
        // Top-right corner
        $pdf->Line($x + $w - $inLen, $y, $x + $w + $outLen, $y);
        $pdf->Line($x + $w, $y - $outLen, $x + $w, $y + $inLen);
        
        // Bottom-left corner
        $pdf->Line($x - $outLen, $y + $h, $x + $inLen, $y + $h);
        $pdf->Line($x, $y + $h - $inLen, $x, $y + $h + $outLen);
        
        // Bottom-right corner
        $pdf->Line($x + $w - $inLen, $y + $h, $x + $w + $outLen, $y + $h);
        $pdf->Line($x + $w, $y + $h - $inLen, $x + $w, $y + $h + $outLen);
    }
    
    /**
     * Draw registration marks in corners
     */
    private function drawRegistrationMarks($pdf, $paper) {
        $size = $this->registrationMarkSize;
        $margin = 5;
        
        // Set line style for registration marks
        $pdf->SetLineWidth(0.15);
        $pdf->SetDrawColor(0, 0, 0);
        
        // Top-left
        $this->drawRegistrationMark($pdf, $margin + $size, $margin + $size, $size);
        
        // Top-right
        $this->drawRegistrationMark($pdf, $paper['width'] - $margin - $size, $margin + $size, $size);
        
        // Bottom-left
        $this->drawRegistrationMark($pdf, $margin + $size, $paper['height'] - $margin - $size, $size);
        
        // Bottom-right
        $this->drawRegistrationMark($pdf, $paper['width'] - $margin - $size, $paper['height'] - $margin - $size, $size);
    }
    
    /**
     * Draw a single registration mark (crosshair with circle)
     */
    private function drawRegistrationMark($pdf, $x, $y, $size) {
        // Draw crosshair
        $pdf->Line($x - $size, $y, $x + $size, $y);
        $pdf->Line($x, $y - $size, $x, $y + $size);
        
        // Draw circle
        $pdf->Circle($x, $y, $size * 0.6, 0, 360, 'D');
    }
    
    /**
     * Load image from path
     */
    private function loadImage($path) {
        $info = getimagesize($path);
        if (!$info) return null;
        
        switch ($info['mime']) {
            case 'image/jpeg':
                return imagecreatefromjpeg($path);
            case 'image/png':
                $img = imagecreatefrompng($path);
                imagealphablending($img, true);
                imagesavealpha($img, true);
                return $img;
            case 'image/gif':
                return imagecreatefromgif($path);
            case 'image/webp':
                return imagecreatefromwebp($path);
            default:
                return null;
        }
    }
    
    /**
     * Convert mm to PDF points
     */
    private function mmToPoints($mm) {
        return $mm * 72 / 25.4;
    }
    
    /**
     * Static helper methods
     */
    public static function getPaperSizes() {
        return self::PAPER_SIZES;
    }
    
    public static function getCardSizes() {
        return self::CARD_SIZES;
    }
}
