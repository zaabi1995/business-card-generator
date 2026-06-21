<?php
/**
 * card-image.php — public PNG of an employee's generated card (front | back).
 *
 *   /card-image.php?i=<employee_id>[&side=front|back]
 *
 * Streams the cached card PNG. Used as the BHD-ERP production Kanban thumbnail
 * (ManufacturingOrder.itemImage) and anywhere a raw card image is needed. The
 * same image is already the public og:image on the digital card, so this is
 * intentionally PUBLIC (the ERP fetches it server-to-server, no session). Falls
 * back to the company OG image, then the Cardify default.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/CardRenderer.php';
require_once INCLUDES_DIR . '/TenantHost.php';

$fallback = function (string $slug = '') {
    if ($slug !== '') {
        header('Location: /og-image.php?slug=' . rawurlencode($slug), true, 302);
        exit;
    }
    $def = __DIR__ . '/assets/images/cardify-og.png';
    if (is_file($def)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        readfile($def);
    } else {
        http_response_code(404);
    }
    exit;
};

$employeeId = trim($_GET['i'] ?? '');
$side = (($_GET['side'] ?? 'front') === 'back') ? 'back' : 'front';
if ($employeeId === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing employee id (use ?i=<employee_id>)';
    exit;
}

$ctx = CardRenderer::forEmployee($employeeId);
if (!$ctx) { $fallback(); }

// Tenant isolation on subdomains: a tenant host only serves its own staff.
if (TenantHost::isTenantHost()) {
    $tslug = (string) TenantHost::slug();
    if ($tslug !== '' && $tslug !== ($ctx['company']['slug'] ?? '')) {
        $fallback();
    }
}

// Requested side, else the other side, else the company OG image.
$fs = $side === 'back' ? ($ctx['back_fs'] ?? null) : ($ctx['front_fs'] ?? null);
if (!$fs) {
    $fs = ($ctx['front_fs'] ?? null) ?: ($ctx['back_fs'] ?? null);
}
if (!$fs || !is_file($fs)) {
    $fallback((string) ($ctx['company']['slug'] ?? ''));
}

$ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));
$mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';

// Optional downscale (?w=NN, 80..2000) so the Kanban thumbnail loads light
// instead of pulling the full ~3000px print PNG. Cached on disk per (file,
// mtime, width); falls through to the original when GD is unavailable.
$w = (int) ($_GET['w'] ?? 0);
if ($w >= 80 && $w <= 2000 && function_exists('imagecreatetruecolor')) {
    $cacheDir = __DIR__ . '/tmp/card-thumbs';
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0775, true); }
    $key = sha1($fs . '|' . filemtime($fs) . '|' . $w) . '.png';
    $cacheFs = $cacheDir . '/' . $key;
    if (!is_file($cacheFs)) {
        $src = $mime === 'image/jpeg' ? @imagecreatefromjpeg($fs) : @imagecreatefrompng($fs);
        if ($src) {
            $sw = imagesx($src); $sh = imagesy($src);
            if ($sw > $w) {
                $nh = (int) round($sh * ($w / $sw));
                $dst = imagecreatetruecolor($w, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $nh, $sw, $sh);
                @imagepng($dst, $cacheFs, 6);
                imagedestroy($dst);
            }
            imagedestroy($src);
        }
    }
    if (is_file($cacheFs)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($cacheFs));
        readfile($cacheFs);
        exit;
    }
}

header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($fs));
readfile($fs);
