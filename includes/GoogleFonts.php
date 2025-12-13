<?php
/**
 * Google Fonts Helper
 * 
 * This class helps download and manage Google Fonts for server-side rendering.
 * It downloads static TTF files from Google Fonts API.
 */

class GoogleFonts {
    
    /**
     * Popular Google Fonts mapping to their download URLs
     * Format: 'FontName' => ['weights' => [...], 'family' => 'URL-encoded name']
     */
    private static $popularFonts = [
        // Modern Sans-Serif
        'Plus Jakarta Sans' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Plus+Jakarta+Sans'],
        'Inter' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Inter'],
        'DM Sans' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'DM+Sans'],
        'Outfit' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Outfit'],
        'Manrope' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Manrope'],
        'Space Grotesk' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Space+Grotesk'],
        'Sora' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Sora'],
        'Urbanist' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Urbanist'],
        'Work Sans' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Work+Sans'],
        'Figtree' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Figtree'],
        'Albert Sans' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Albert+Sans'],
        'Rubik' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Rubik'],
        
        // Classic Sans-Serif
        'Montserrat' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Montserrat'],
        'Roboto' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Roboto'],
        'Open Sans' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Open+Sans'],
        'Lato' => ['weights' => ['Regular', 'Bold'], 'family' => 'Lato'],
        'Poppins' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Poppins'],
        'Raleway' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Raleway'],
        'Nunito' => ['weights' => ['Regular', 'Bold'], 'family' => 'Nunito'],
        'Quicksand' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Quicksand'],
        'Karla' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Karla'],
        'Source Sans 3' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Source+Sans+3'],
        'Source Sans Pro' => ['weights' => ['Regular', 'Bold'], 'family' => 'Source+Sans+Pro'],
        
        // Serif / Elegant
        'Playfair Display' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Playfair+Display'],
        'Merriweather' => ['weights' => ['Regular', 'Bold'], 'family' => 'Merriweather'],
        'Lora' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Lora'],
        'Crimson Pro' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Crimson+Pro'],
        'Cormorant Garamond' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Cormorant+Garamond'],
        'Libre Baskerville' => ['weights' => ['Regular', 'Bold'], 'family' => 'Libre+Baskerville'],
        'EB Garamond' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'EB+Garamond'],
        
        // Display / Bold
        'Oswald' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Oswald'],
        'Bebas Neue' => ['weights' => ['Regular'], 'family' => 'Bebas+Neue'],
        'Anton' => ['weights' => ['Regular'], 'family' => 'Anton'],
        'Abril Fatface' => ['weights' => ['Regular'], 'family' => 'Abril+Fatface'],
        
        // Arabic - Modern
        'Noto Sans Arabic' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Noto+Sans+Arabic'],
        'Cairo' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Cairo'],
        'Tajawal' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Tajawal'],
        'Almarai' => ['weights' => ['Regular', 'Bold'], 'family' => 'Almarai'],
        'IBM Plex Sans Arabic' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'IBM+Plex+Sans+Arabic'],
        'Readex Pro' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Readex+Pro'],
        'Mada' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Mada'],
        'Changa' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Changa'],
        
        // Arabic - Decorative
        'El Messiri' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'El+Messiri'],
        'Reem Kufi' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Reem+Kufi'],
        'Noto Kufi Arabic' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Noto+Kufi+Arabic'],
        'Baloo Bhaijaan 2' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Baloo+Bhaijaan+2'],
        'Lalezar' => ['weights' => ['Regular'], 'family' => 'Lalezar'],
        
        // Arabic - Traditional / Naskh
        'Amiri' => ['weights' => ['Regular', 'Bold'], 'family' => 'Amiri'],
        'Scheherazade New' => ['weights' => ['Regular', 'Bold'], 'family' => 'Scheherazade+New'],
        'Lateef' => ['weights' => ['Regular', 'Bold'], 'family' => 'Lateef'],
        'Harmattan' => ['weights' => ['Regular', 'Bold'], 'family' => 'Harmattan'],
        'Noto Naskh Arabic' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Noto+Naskh+Arabic'],
        
        // Arabic - Calligraphic
        'Aref Ruqaa' => ['weights' => ['Regular', 'Bold'], 'family' => 'Aref+Ruqaa'],
        'Markazi Text' => ['weights' => ['Regular', 'Medium', 'Bold'], 'family' => 'Markazi+Text'],
    ];
    
    /**
     * Weight name to number mapping
     */
    private static $weightMap = [
        'Thin' => 100,
        'ExtraLight' => 200,
        'Light' => 300,
        'Regular' => 400,
        'Medium' => 500,
        'SemiBold' => 600,
        'Bold' => 700,
        'ExtraBold' => 800,
        'Black' => 900
    ];
    
    /**
     * Get the font directory path
     */
    private static function getFontDir() {
        return defined('BASE_DIR') ? BASE_DIR . '/assets/fonts' : __DIR__ . '/../assets/fonts';
    }
    
    /**
     * Normalize font name for file naming
     * e.g., "Plus Jakarta Sans" -> "PlusJakartaSans"
     */
    public static function normalizeFontName($fontName) {
        $parts = preg_split('/\s+/', trim($fontName));
        $result = '';
        foreach ($parts as $part) {
            $result .= ucfirst(strtolower(trim($part)));
        }
        return $result;
    }
    
    /**
     * Get TTF file path for a font with specific weight
     */
    public static function getFontPath($fontName, $weight = 'Regular') {
        $fontDir = self::getFontDir();
        $normalizedName = self::normalizeFontName($fontName);
        
        // Map weight number to name if needed
        if (is_numeric($weight)) {
            $weight = self::getWeightName((int)$weight);
        }
        
        // Build expected file path
        $filePath = $fontDir . '/' . $normalizedName . '-' . $weight . '.ttf';
        
        if (file_exists($filePath)) {
            return $filePath;
        }
        
        // Try alternative naming (underscore)
        $altPath = $fontDir . '/' . $normalizedName . '_' . $weight . '.ttf';
        if (file_exists($altPath)) {
            return $altPath;
        }
        
        // Case-insensitive search through all font files
        $allFiles = glob($fontDir . '/*.ttf');
        $normalizedLower = strtolower($normalizedName);
        
        foreach ($allFiles as $file) {
            $filename = basename($file, '.ttf');
            $filenameLower = strtolower($filename);
            
            // Check if file matches font name (case-insensitive)
            if (stripos($filenameLower, $normalizedLower) === 0) {
                // Check if weight matches
                if (stripos($filename, '-' . $weight) !== false || stripos($filename, '_' . $weight) !== false) {
                    return $file;
                }
            }
        }
        
        // Second pass: find any file with this font name
        foreach ($allFiles as $file) {
            $filename = basename($file, '.ttf');
            $filenameLower = strtolower($filename);
            
            if (stripos($filenameLower, $normalizedLower) === 0) {
                return $file;
            }
        }
        
        return null;
    }
    
    /**
     * Convert weight number to name
     */
    public static function getWeightName($weightNumber) {
        $closest = 'Regular';
        $minDiff = PHP_INT_MAX;
        
        foreach (self::$weightMap as $name => $number) {
            $diff = abs($number - $weightNumber);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $name;
            }
        }
        
        return $closest;
    }
    
    /**
     * Convert weight name to number
     */
    public static function getWeightNumber($weightName) {
        return self::$weightMap[$weightName] ?? 400;
    }
    
    /**
     * Download a Google Font
     * Returns true on success, false on failure
     */
    public static function downloadFont($fontName, $weights = ['Regular', 'Medium', 'Bold']) {
        $fontDir = self::getFontDir();
        $normalizedName = self::normalizeFontName($fontName);
        
        // Ensure font directory exists
        if (!is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);
        }
        
        // Check if font info exists in our list
        $fontInfo = null;
        foreach (self::$popularFonts as $name => $info) {
            if (strcasecmp($name, $fontName) === 0 || 
                strcasecmp(self::normalizeFontName($name), $normalizedName) === 0) {
                $fontInfo = $info;
                $fontName = $name;
                break;
            }
        }
        
        if (!$fontInfo) {
            // Font not in our list, try to guess the URL format
            $fontInfo = [
                'family' => str_replace(' ', '+', $fontName),
                'weights' => $weights
            ];
        }
        
        $success = false;
        
        // Build weight query string for all weights at once
        $weightNumbers = [];
        foreach ($weights as $weight) {
            $weightNumbers[] = is_numeric($weight) ? $weight : self::getWeightNumber($weight);
        }
        $weightQuery = implode(';', $weightNumbers);
        
        // Fetch CSS with all weights
        $cssUrl = "https://fonts.googleapis.com/css2?family={$fontInfo['family']}:wght@{$weightQuery}&display=swap";
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n",
                'timeout' => 30
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $css = @file_get_contents($cssUrl, false, $context);
        
        if (!$css) {
            error_log("GoogleFonts: Failed to fetch CSS from $cssUrl");
            return false;
        }
        
        // Parse CSS and extract font URLs for each weight
        // Pattern: @font-face { ... font-weight: XXX; ... src: url(https://...) ... }
        preg_match_all('/@font-face\s*\{[^}]*font-weight:\s*(\d+)[^}]*src:\s*url\(([^)]+)\)[^}]*\}/s', $css, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $fontWeight = (int)$match[1];
            $fontUrl = $match[2];
            $weightName = self::getWeightName($fontWeight);
            $targetPath = $fontDir . '/' . $normalizedName . '-' . $weightName . '.ttf';
            
            // Skip if already exists and is valid
            if (file_exists($targetPath) && filesize($targetPath) > 1000) {
                $success = true;
                continue;
            }
            
            // Download the font file
            $fontData = @file_get_contents($fontUrl, false, $context);
            
            if ($fontData && strlen($fontData) > 1000) {
                if (file_put_contents($targetPath, $fontData)) {
                    $success = true;
                    error_log("GoogleFonts: Downloaded $fontName $weightName to $targetPath");
                }
            } else {
                error_log("GoogleFonts: Failed to download font from $fontUrl");
            }
        }
        
        return $success;
    }
    
    /**
     * Get list of available fonts in the fonts directory
     */
    public static function getAvailableFonts() {
        $fontDir = self::getFontDir();
        $fonts = [];
        
        if (!is_dir($fontDir)) {
            return $fonts;
        }
        
        $files = glob($fontDir . '/*.ttf');
        
        foreach ($files as $file) {
            $filename = basename($file, '.ttf');
            // Parse font name and weight from filename (e.g., "PlusJakartaSans-Bold")
            if (preg_match('/^(.+?)[-_](Regular|Medium|Bold|Light|Thin|Black|SemiBold|ExtraBold|ExtraLight)$/i', $filename, $matches)) {
                $fontName = $matches[1];
                $weight = $matches[2];
                
                if (!isset($fonts[$fontName])) {
                    $fonts[$fontName] = ['weights' => [], 'path' => dirname($file)];
                }
                $fonts[$fontName]['weights'][] = $weight;
            } else {
                // Font file without weight suffix
                $fonts[$filename] = ['weights' => ['Regular'], 'path' => dirname($file)];
            }
        }
        
        return $fonts;
    }
    
    /**
     * Check if a font is available locally
     */
    public static function isFontAvailable($fontName, $weight = 'Regular') {
        return self::getFontPath($fontName, $weight) !== null;
    }
    
    /**
     * Ensure a font is available, downloading if necessary
     */
    public static function ensureFont($fontName, $weights = ['Regular', 'Medium', 'Bold']) {
        $normalizedName = self::normalizeFontName($fontName);
        $allAvailable = true;
        
        foreach ($weights as $weight) {
            if (!self::isFontAvailable($fontName, $weight)) {
                $allAvailable = false;
                break;
            }
        }
        
        if (!$allAvailable) {
            // Try to download
            self::downloadFont($fontName, $weights);
        }
        
        // Return the path to the regular weight
        return self::getFontPath($fontName, 'Regular');
    }
    
    /**
     * Get list of popular Google Fonts
     */
    public static function getPopularFonts() {
        return array_keys(self::$popularFonts);
    }
}

