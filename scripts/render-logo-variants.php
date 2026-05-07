<?php
/**
 * scripts/render-logo-variants.php
 *
 * For each om_companies row with a source logo, generate missing PNG
 * variants (512, 1024, 2048) and a web-optimized WebP.
 *
 * SVG → PNG via ImageMagick if the ext is loaded, else skipped with warning.
 * PNG source → PNG size variants via GD (downsample only; never upscale).
 *
 * Run: php scripts/render-logo-variants.php [--company-id=N] [--only-missing]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

$opts = getopt('', ['company-id::', 'only-missing']);
$ID   = isset($opts['company-id']) ? (int) $opts['company-id'] : 0;
$MISS = isset($opts['only-missing']);

$db   = Database::getInstance();
$pdo  = $db->getConnection();

$sql = "SELECT id, logo_svg_path, logo_png_path, logo_png_512_path, logo_png_2048_path, logo_webp_path
        FROM om_companies
        WHERE (logo_svg_path IS NOT NULL OR logo_png_path IS NOT NULL)";
if ($ID > 0) $sql .= " AND id = " . $ID;

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$root = dirname(__DIR__);
$hasMagick = class_exists('Imagick');

echo "[render] rows=" . count($rows) . " imagemagick=" . ($hasMagick ? 'yes' : 'no') . "\n";

function outPngPath(string $root, int $id, int $size): array {
    $rel = $size === 1024
        ? "/storage/logos/indexed/{$id}.png"
        : "/storage/logos/indexed/{$id}-{$size}.png";
    return [$rel, $root . $rel];
}

function outWebpPath(string $root, int $id): array {
    $rel = "/storage/logos/indexed/{$id}.webp";
    return [$rel, $root . $rel];
}

function renderPngFromSvg(Imagick $im, string $svgAbs, int $size, string $outAbs): bool {
    try {
        $im->clear();
        $im->setBackgroundColor(new ImagickPixel('transparent'));
        $im->readImage($svgAbs);
        $im->setImageFormat('png');
        $im->thumbnailImage($size, 0);
        $im->writeImage($outAbs);
        return true;
    } catch (Throwable $t) {
        error_log("[render] svg->png failed $svgAbs @ $size: " . $t->getMessage());
        return false;
    }
}

function renderPngDownscale(string $pngSrcAbs, int $size, string $outAbs): bool {
    [$w, $h] = getimagesize($pngSrcAbs);
    if (!$w || !$h) return false;
    if ($w <= $size) return false; // never upscale
    $src = @imagecreatefrompng($pngSrcAbs);
    if (!$src) return false;
    $newH = (int) round($h * $size / $w);
    $dst = imagecreatetruecolor($size, $newH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $newH, $w, $h);
    $ok = imagepng($dst, $outAbs, 9);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

function renderWebpFromPng(string $pngAbs, string $outAbs): bool {
    $img = @imagecreatefrompng($pngAbs);
    if (!$img) return false;
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $ok = imagewebp($img, $outAbs, 85);
    imagedestroy($img);
    return $ok;
}

$magick  = $hasMagick ? new Imagick() : null;
$updated = 0;

foreach ($rows as $r) {
    $id     = (int) $r['id'];
    $svgAbs = $r['logo_svg_path'] ? $root . $r['logo_svg_path'] : null;
    $pngAbs = $r['logo_png_path'] ? $root . $r['logo_png_path'] : null;

    $updates = [];

    // Process sizes in DESCENDING order. Size 1024 writes to
    // /storage/logos/indexed/{id}.png — which is ALSO $pngAbs (the source).
    // If we ran ascending, 1024 would overwrite the source, then 2048 would
    // read the already-downsampled 1024 render and fail the no-upscale guard.
    // Descending means 2048 reads the pristine original first, then 1024
    // replaces the source (fine since it's the canonical column itself), then
    // 512 downsamples from the 1024 render which is still acceptable for the
    // smaller tile.
    foreach ([2048, 1024, 512] as $size) {
        $col = $size === 1024 ? 'logo_png_path' : "logo_png_{$size}_path";
        [$rel, $abs] = outPngPath($root, $id, $size);
        if ($MISS && !empty($r[$col])) continue;

        if ($svgAbs && is_file($svgAbs) && $hasMagick) {
            if (renderPngFromSvg($magick, $svgAbs, $size, $abs)) {
                $updates[$col] = $rel;
            }
        } elseif ($pngAbs && is_file($pngAbs)) {
            if (renderPngDownscale($pngAbs, $size, $abs)) {
                $updates[$col] = $rel;
            }
        }
    }

    // WebP (for web display)
    [$webRel, $webAbs] = outWebpPath($root, $id);
    if (!($MISS && !empty($r['logo_webp_path']))) {
        // Prefer whatever 1024 PNG we have (either just rendered or existing)
        $source1024 = $updates['logo_png_path'] ?? $r['logo_png_path'] ?? null;
        $sourceAbs = $source1024 ? $root . $source1024 : null;
        if ($sourceAbs && is_file($sourceAbs) && renderWebpFromPng($sourceAbs, $webAbs)) {
            $updates['logo_webp_path'] = $webRel;
        }
    }

    if ($updates) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($updates)));
        $params = [];
        foreach ($updates as $k => $v) $params[":$k"] = $v;
        $params[':id'] = $id;
        $pdo->prepare("UPDATE om_companies SET $set, logo_updated_at = NOW() WHERE id = :id")
            ->execute($params);
        $updated++;
    }
}

if ($magick) $magick->clear();
echo "[render] updated=$updated\n";
