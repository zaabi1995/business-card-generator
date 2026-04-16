<?php
/**
 * Cardify per-company OG image compositor.
 *
 * Takes a sector background (from /assets/images/og-sectors/{sector}.jpg)
 * and composites the company name + governorate + Cardify lockup on top.
 * Caches the rendered JPG to /cache/og/{slug}.jpg for 7 days.
 *
 * URL: /og/company/{slug}.jpg   (see nginx rewrite)
 * Or:  /og.php?slug={slug}
 */
require_once __DIR__ . '/config.php';

$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9-]/', '', $_GET['slug']) : '';
if ($slug === '') {
    http_response_code(400);
    exit;
}

$db = Database::getInstance();
$company = $db->fetchOne(
    "SELECT name_en, name_ar, sector, wilayat FROM om_companies WHERE slug = ? LIMIT 1",
    [$slug]
);
if (!$company) {
    header('Location: /assets/images/cardify-og.png', true, 302);
    exit;
}

$cacheDir = __DIR__ . '/cache/og';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheFile = $cacheDir . '/' . $slug . '.jpg';

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 7 * 86400) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=604800, immutable');
    header('Content-Length: ' . filesize($cacheFile));
    readfile($cacheFile);
    exit;
}

// --- Compose the image ---
$bgPath = __DIR__ . '/assets/images/og-sectors/' . $company['sector'] . '.jpg';
if (!is_file($bgPath)) {
    $bgPath = __DIR__ . '/assets/images/og-sectors/other.jpg';
}
if (!is_file($bgPath)) {
    // Fallback to generic
    header('Location: /assets/images/cardify-og.png', true, 302);
    exit;
}

$SECTORS = [
    'oil-gas' => 'Oil & Gas', 'construction' => 'Construction', 'trading' => 'Trading',
    'finance' => 'Finance & Banking', 'real-estate' => 'Real Estate',
    'manufacturing' => 'Manufacturing', 'logistics-shipping' => 'Logistics & Shipping',
    'food-beverage' => 'Food & Beverage', 'healthcare' => 'Healthcare',
    'education' => 'Education', 'hospitality-tourism' => 'Hospitality & Tourism',
    'technology' => 'Technology', 'telecom' => 'Telecommunications',
    'automotive' => 'Automotive', 'retail' => 'Retail',
    'agriculture-fisheries' => 'Agriculture & Fisheries', 'mining' => 'Mining',
    'utilities' => 'Utilities', 'media-advertising' => 'Media & Advertising',
    'professional-services' => 'Professional Services',
    'government-defense' => 'Government & Defense',
    'conglomerate' => 'Conglomerate', 'other' => 'Enterprise',
];
$WILAYATS = [
    'muscat' => 'Muscat', 'dhofar' => 'Dhofar', 'musandam' => 'Musandam',
    'al-buraimi' => 'Al Buraimi', 'al-dakhiliyah' => 'Al Dakhiliyah',
    'al-dhahirah' => 'Al Dhahirah', 'al-sharqiyah-north' => 'Ash Sharqiyah North',
    'al-sharqiyah-south' => 'Ash Sharqiyah South', 'al-batinah-north' => 'Al Batinah North',
    'al-batinah-south' => 'Al Batinah South', 'al-wusta' => 'Al Wusta',
];
$sectorLabel  = $SECTORS[$company['sector']] ?? 'Enterprise';
$wilayatLabel = $WILAYATS[$company['wilayat']] ?? 'Oman';
$name = $company['name_en'];
// Trim common corporate suffixes for cleaner titles
$name = preg_replace('/\s*(L\.L\.C\.?|LLC|S\.A\.O\.?G\.?|SAOG|S\.A\.O\.C\.?|SAOC|SPC|S\.P\.C\.?|\(.*?\))\s*$/i', '', $name);
$name = trim($name);

$W = 1200;
$H = 630;

// Load background
$bg = imagecreatefromjpeg($bgPath);
if (!$bg) {
    header('Location: /assets/images/cardify-og.png', true, 302);
    exit;
}
$canvas = imagecreatetruecolor($W, $H);
imagecopyresampled($canvas, $bg, 0, 0, 0, 0, $W, $H, imagesx($bg), imagesy($bg));
imagedestroy($bg);

// Dark gradient overlay on the left 60% for text legibility
for ($x = 0; $x < $W; $x++) {
    $ratio = $x / $W;
    $alpha = (int) max(0, (0.85 - $ratio * 1.2) * 127);
    if ($alpha > 0) {
        $col = imagecolorallocatealpha($canvas, 10, 15, 25, 127 - $alpha);
        imageline($canvas, $x, 0, $x, $H, $col);
    }
}

// Text colors
$white = imagecolorallocate($canvas, 255, 255, 255);
$cream = imagecolorallocate($canvas, 240, 240, 245);
$accent = imagecolorallocate($canvas, 96, 165, 250); // blue-400

// Font lookup — use first TTF found on system (Inter/DejaVu/Arial fallback)
$fontCandidates = [
    __DIR__ . '/assets/fonts/Inter-Bold.ttf',
    __DIR__ . '/assets/fonts/Inter-SemiBold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVu-Sans-Bold.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
];
$fontBold = '';
foreach ($fontCandidates as $f) {
    if (is_file($f)) { $fontBold = $f; break; }
}

if ($fontBold) {
    // Sector eyebrow (small, accent color)
    imagettftext($canvas, 22, 0, 70, 150, $accent, $fontBold, strtoupper($sectorLabel . ' · ' . $wilayatLabel));

    // Main company name — auto-shrink font + wrap to fit left 60% in max 2 lines
    $maxWidth = (int) ($W * 0.58);
    $words = explode(' ', $name);

    // Try sizes from 62 down to 32, find the largest that fits in ≤2 lines
    $lines = [];
    $nameFontSize = 62;
    for ($trySize = 62; $trySize >= 32; $trySize -= 4) {
        $candidate = [];
        $current = '';
        foreach ($words as $w) {
            $probe = trim($current . ' ' . $w);
            $bbox = imagettfbbox($trySize, 0, $fontBold, $probe);
            if (($bbox[2] - $bbox[0]) > $maxWidth && $current !== '') {
                $candidate[] = $current;
                $current = $w;
            } else {
                $current = $probe;
            }
        }
        if ($current !== '') $candidate[] = $current;

        if (count($candidate) <= 2) {
            $lines = $candidate;
            $nameFontSize = $trySize;
            break;
        }
    }
    // Fallback: if still >2 lines at size 32, take first 2 lines + ellipsis
    if (empty($lines)) {
        $nameFontSize = 32;
        $current = '';
        foreach ($words as $w) {
            $probe = trim($current . ' ' . $w);
            $bbox = imagettfbbox($nameFontSize, 0, $fontBold, $probe);
            if (($bbox[2] - $bbox[0]) > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $w;
                if (count($lines) >= 2) break;
            } else {
                $current = $probe;
            }
        }
        if (count($lines) < 2 && $current !== '') $lines[] = $current;
        if (count($lines) >= 2) $lines[1] = rtrim($lines[1], ' ') . '…';
    }

    $lineHeight = (int) ($nameFontSize * 1.15);
    $startY = 230;
    foreach ($lines as $i => $line) {
        imagettftext($canvas, $nameFontSize, 0, 70, $startY + $i * $lineHeight, $white, $fontBold, $line);
    }

    // Footer: Cardify brand lockup
    imagettftext($canvas, 20, 0, 70, $H - 60, $cream, $fontBold, 'cardify.om');
    imagettftext($canvas, 16, 0, 70, $H - 35, $cream, $fontBold, 'Oman Business Index');
} else {
    // No TTF available — fallback to built-in font (ugly but safe)
    imagestring($canvas, 5, 70, 200, $name, $white);
    imagestring($canvas, 3, 70, $H - 50, 'cardify.om', $cream);
}

// Output + cache
imagejpeg($canvas, $cacheFile, 85);
imagedestroy($canvas);

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800, immutable');
header('Content-Length: ' . filesize($cacheFile));
readfile($cacheFile);
