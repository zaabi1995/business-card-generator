<?php
/**
 * Font Awesome Pro Local Asset Manager
 * Downloads and caches Font Awesome Pro CSS files locally
 */

function ensureFontAwesomeAssets() {
    $vendorBase = __DIR__ . '/../assets/vendor/fontawesome';
    $cssDir = $vendorBase . '/css';
    $mainCss = $cssDir . '/all.css';
    
    // If already downloaded, skip
    if (file_exists($mainCss)) {
        return true;
    }
    
    // Create directories
    if (!is_dir($cssDir)) {
        @mkdir($cssDir, 0755, true);
    }
    
    // Font Awesome Pro v7.1.0 CSS files to download
    $cssFiles = [
        'all.css',
        'sharp-solid.css',
        'sharp-regular.css',
        'sharp-light.css',
        'duotone.css'
    ];
    
    $cdnBase = 'https://site-assets.fontawesome.com/releases/v7.1.0/css';
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'Mozilla/5.0 (compatible; Cardify/1.0)'
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $success = true;
    foreach ($cssFiles as $file) {
        $localPath = $cssDir . '/' . $file;
        if (!file_exists($localPath)) {
            $url = $cdnBase . '/' . $file;
            $content = @file_get_contents($url, false, $context);
            if ($content !== false) {
                @file_put_contents($localPath, $content);
            } else {
                $success = false;
            }
        }
    }
    
    return $success && file_exists($mainCss);
}

/**
 * Get the local Font Awesome CSS path
 */
function getFontAwesomePath() {
    return '/assets/vendor/fontawesome/css/';
}
