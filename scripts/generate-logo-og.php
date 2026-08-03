<?php
/**
 * scripts/generate-logo-og.php
 *
 * Generates per-sector OG images at 1200x630 for /logos/{sector}.
 * Also generates a hub OG (/logos).
 *
 * Run: php scripts/generate-logo-og.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config.php';

$SECTORS = [
    'oil-gas', 'construction', 'trading', 'finance', 'real-estate', 'manufacturing',
    'logistics-shipping', 'food-beverage', 'healthcare', 'education', 'hospitality-tourism',
    'technology', 'telecom', 'automotive', 'retail', 'agriculture-fisheries', 'mining',
    'utilities', 'media-advertising', 'professional-services', 'government-defense',
    'conglomerate', 'other',
];

$SECTOR_LABELS = [
    'oil-gas' => 'Oil & Gas', 'construction' => 'Construction', 'trading' => 'Trading',
    'finance' => 'Finance & Banking', 'real-estate' => 'Real Estate',
    'manufacturing' => 'Manufacturing', 'logistics-shipping' => 'Logistics',
    'food-beverage' => 'Food & Beverage', 'healthcare' => 'Healthcare',
    'education' => 'Education', 'hospitality-tourism' => 'Hospitality',
    'technology' => 'Technology', 'telecom' => 'Telecom', 'automotive' => 'Automotive',
    'retail' => 'Retail', 'agriculture-fisheries' => 'Agriculture', 'mining' => 'Mining',
    'utilities' => 'Utilities', 'media-advertising' => 'Media',
    'professional-services' => 'Professional Services', 'government-defense' => 'Government',
    'conglomerate' => 'Conglomerate', 'other' => 'Other',
];

$db     = Database::getInstance();
$outDir = dirname(__DIR__) . '/storage/og/logos';
@mkdir($outDir, 0755, true);

function findFont(): ?string {
    foreach ([
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/Library/Fonts/Arial.ttf',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
    ] as $f) {
        if (is_file($f)) return $f;
    }
    return null;
}

function drawCard(string $title, string $subtitle, array $logoPaths, string $outPath): void {
    $W = 1200; $H = 630;
    $img = imagecreatetruecolor($W, $H);

    $bg     = imagecolorallocate($img, 248, 250, 252);  // slate-50
    $ink    = imagecolorallocate($img, 15, 23, 42);     // slate-900
    $mut    = imagecolorallocate($img, 100, 116, 139);  // slate-500
    $accent = imagecolorallocate($img, 8, 145, 178);    // cyan-700
    $white  = imagecolorallocate($img, 255, 255, 255);

    imagefill($img, 0, 0, $bg);
    imagefilledrectangle($img, 0, 0, $W, 80, $white);

    $font = findFont();
    if ($font) {
        imagettftext($img, 36, 0, 60, 160, $ink,    $font, $title);
        imagettftext($img, 20, 0, 60, 200, $mut,    $font, $subtitle);
        imagettftext($img, 14, 0, 60, 600, $accent, $font, 'cardify.om/logos');
    } else {
        imagestring($img, 5, 60, 140, $title, $ink);
        imagestring($img, 3, 60, 170, $subtitle, $mut);
        imagestring($img, 3, 60, 590, 'cardify.om/logos', $accent);
    }

    // 3x2 logo grid
    $tileW = 280; $tileH = 180; $gapX = 20; $gapY = 20;
    $startX = 60; $startY = 240;
    foreach (array_slice($logoPaths, 0, 6) as $i => $path) {
        $col = $i % 3;
        $row = intdiv($i, 3);
        $x = $startX + $col * ($tileW + $gapX);
        $y = $startY + $row * ($tileH + $gapY);
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $logo = match ($ext) {
            'png'        => @imagecreatefrompng($path),
            'jpg', 'jpeg'=> @imagecreatefromjpeg($path),
            'webp'       => @imagecreatefromwebp($path),
            default      => null,
        };
        if (!$logo) continue;
        $lw = imagesx($logo); $lh = imagesy($logo);
        $scale = min($tileW / $lw, $tileH / $lh, 1.0);
        $nw = (int) ($lw * $scale);
        $nh = (int) ($lh * $scale);
        $dx = $x + (int) (($tileW - $nw) / 2);
        $dy = $y + (int) (($tileH - $nh) / 2);
        imagecopyresampled($img, $logo, $dx, $dy, 0, 0, $nw, $nh, $lw, $lh);
        imagedestroy($logo);
    }

    imagepng($img, $outPath, 6);
    imagedestroy($img);
}

// Hub card
$hubLogos = $db->fetchAll(
    "SELECT logo_png_path FROM om_companies
      WHERE logo_status = 'verified' AND logo_png_path IS NOT NULL
      ORDER BY logo_verified_at DESC LIMIT 6"
);
if (count($hubLogos) < 6) {
    $hubLogos = array_merge($hubLogos, $db->fetchAll(
        "SELECT logo_png_path FROM om_companies
          WHERE logo_status IN ('indexed','verified') AND logo_png_path IS NOT NULL
          ORDER BY RAND() LIMIT 6"
    ));
}
drawCard(
    'The Omani Logo Library',
    LogoLibrary::archiveFloor() . '+ Omani brands, one archive',
    array_map(fn($r) => dirname(__DIR__) . $r['logo_png_path'], $hubLogos),
    "$outDir/hub.png"
);
echo "[og] hub.png\n";

foreach ($SECTORS as $slug) {
    $rows = $db->fetchAll(
        "SELECT logo_png_path FROM om_companies
          WHERE sector = :s AND logo_png_path IS NOT NULL
            AND logo_status IN ('indexed', 'verified')
          ORDER BY RAND() LIMIT 6",
        [':s' => $slug]
    );
    $paths = array_map(fn($r) => dirname(__DIR__) . $r['logo_png_path'], $rows);
    $label = $SECTOR_LABELS[$slug] ?? $slug;
    $count = (int) ($db->fetchOne(
        "SELECT COUNT(*) c FROM om_companies
          WHERE sector = :s AND logo_status IN ('indexed', 'verified')",
        [':s' => $slug]
    )['c'] ?? 0);
    drawCard(
        "Omani $label Logos",
        "$count brands indexed",
        $paths,
        "$outDir/$slug.png"
    );
    echo "[og] $slug.png\n";
}

echo "[og] done\n";
