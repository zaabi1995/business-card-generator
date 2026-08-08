<?php
/**
 * card-image.php - public image of an employee's generated card (front | back).
 *
 *   /card-image.php?i=<employee_id>[&side=front|back][&w=80..2000]
 *
 * Streams the cached card image AS STORED. It is not necessarily a PNG: the
 * renderer emits WebP for most cards today, which is why the Content-Type is
 * derived from the file's contents (cardImageMime below) rather than assumed.
 * The optional ?w= thumbnail is always re-encoded to PNG and says so.
 *
 * Used as the BHD-ERP production Kanban thumbnail
 * (ManufacturingOrder.itemImage) and anywhere a raw card image is needed. The
 * same image is already the public og:image on the digital card, so this is
 * intentionally PUBLIC (the ERP fetches it server-to-server, no session). Falls
 * back to the company OG image, then the Cardify default.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/CardRenderer.php';
require_once INCLUDES_DIR . '/TenantHost.php';

/** Image types this endpoint is willing to name in a Content-Type header. */
const CARD_IMAGE_MIMES = [
    'image/png',
    'image/jpeg',
    'image/webp',
    'image/gif',
    'image/avif',
    'image/svg+xml',
];

/** Extension map, used only when fileinfo is unavailable. */
const CARD_IMAGE_EXT_MIMES = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'avif' => 'image/avif',
    'svg'  => 'image/svg+xml',
];

/**
 * The real media type of $fs, read from its contents.
 *
 * finfo can answer 'image/svg' or 'text/xml' for an SVG depending on the magic
 * database, so an SVG extension resolves the ambiguity rather than the sniff
 * being trusted blindly. Anything unrecognised falls back to the extension map
 * and finally to image/png, which is exactly what this endpoint sent before,
 * so an unknown format is no worse off than it was.
 */
function cardImageMime(string $fs): string
{
    $ext = strtolower(pathinfo($fs, PATHINFO_EXTENSION));

    $sniffed = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $sniffed = (string) ($finfo->file($fs) ?: '');
    }
    if ($sniffed === 'image/svg' || (
        $ext === 'svg' && in_array($sniffed, ['text/xml', 'text/plain', 'application/xml'], true)
    )) {
        $sniffed = 'image/svg+xml';
    }
    if (in_array($sniffed, CARD_IMAGE_MIMES, true)) {
        return $sniffed;
    }

    return CARD_IMAGE_EXT_MIMES[$ext] ?? 'image/png';
}

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

/**
 * Content-Type from the BYTES, not the filename.
 *
 * This used to be `($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png'`,
 * a two-way ternary over the file extension, so every format that is not JPEG
 * was labelled image/png. WebP is a first-class upload format everywhere else
 * in the product (portal.php, onboarding.php, digital_card.php all accept
 * image/webp) and the renderer emits it, so live cards were served as
 * `RIFF....WEBP` bytes under `Content-Type: image/png`. The response also
 * carries X-Content-Type-Options: nosniff from config.php, so no client is
 * allowed to correct it, and this endpoint is the public og:image: an OG
 * scraper trusts the declared type and drops the link preview.
 *
 * CLAUDE.md already states the rule for uploads, "detect MIME from file
 * contents via finfo, never trust the extension". It applies on the way out
 * too. finfo first; the extension map is only a fallback for when fileinfo is
 * not loaded, and image/png stays the last resort so behaviour never regresses
 * to no Content-Type at all.
 */
$mime = cardImageMime($fs);

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
        // Decode with the loader that matches the ACTUAL type. This used to be
        // jpeg-or-png, so a WebP card was handed to imagecreatefrompng(), which
        // failed silently and left every WebP card without a thumbnail (it fell
        // through to the full ~3000px original). The output is always re-encoded
        // to PNG below, which is why the cached response declares image/png.
        switch ($mime) {
            case 'image/jpeg':
                $src = @imagecreatefromjpeg($fs);
                break;
            case 'image/webp':
                $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fs) : false;
                break;
            case 'image/gif':
                $src = function_exists('imagecreatefromgif') ? @imagecreatefromgif($fs) : false;
                break;
            case 'image/avif':
                $src = function_exists('imagecreatefromavif') ? @imagecreatefromavif($fs) : false;
                break;
            case 'image/svg+xml':
                // Vector: GD cannot decode it and downscaling is meaningless.
                $src = false;
                break;
            default:
                $src = @imagecreatefrompng($fs);
        }
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
if ($mime === 'image/svg+xml') {
    // An SVG is a document, not a bitmap: served inline from our own origin it
    // can run script. Naming the type honestly is still right, but it has to
    // come with a policy that makes the document inert.
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
}
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($fs));
readfile($fs);
