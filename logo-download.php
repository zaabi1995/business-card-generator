<?php
/**
 * Signed logo download endpoint with rate limit + analytics.
 *
 * Indexed and verified logos are downloadable. Each download is logged to
 * logo_downloads (hashed IP/UA). Quotas are checked when a ticket is issued.
 *
 * GET /logo-download?company=NNN&format=svg|png_512|png_1024|png_2048|webp|zip
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/LogoAccess.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';
SecurityHeaders::send();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Cache-Control: private, no-store');

$db = Database::getInstance();
$companyId = (int) ($_GET['company'] ?? 0);
$format    = $_GET['format'] ?? '';
$allowed = LogoAccess::FORMATS;

if (!$companyId || !in_array($format, $allowed, true)) {
    http_response_code(400);
    die('Bad request');
}

$company = $db->fetchOne("SELECT * FROM om_companies WHERE id = :id", [':id' => $companyId]);
if (!$company) {
    http_response_code(404);
    die('Not found');
}

if (!LogoLibrary::canDownload($company)) {
    // Downloads allowed for indexed + verified. Blocked for 'none', 'pending',
    // 'disputed', or 'takedown' states.
    http_response_code(403);
    $msg = match ($company['logo_status'] ?? 'none') {
        'takedown' => 'This logo has been removed at the brand owner\'s request.',
        'disputed' => 'This logo is under review.',
        'pending'  => 'A claim is pending review for this logo.',
        default    => 'No logo available for download.',
    };
    die($msg);
}

// The legacy lead cookie is not an entitlement. Only a short-lived ticket
// issued by the POST access endpoint authorizes a download.
if (!LogoAccess::consumeTicket((string)($_GET['ticket'] ?? ''), $companyId, $format)) {
    $prefix = currentLocale() === 'ar' ? '/ar' : '';
    header('Location: ' . $prefix . '/companies/' . rawurlencode($company['slug']) . '?download=required&format=' . rawurlencode($format), true, 303);
    exit;
}
$ipHash = LogoLibrary::ipHash();
$unlockCookie = $_COOKIE['cardify_logo_unlock_v1'] ?? null;
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$paths = LogoLibrary::downloadPaths($company);
// Only serve files beneath the logo storage root, even if a DB path is malformed.
$logoRoot = realpath(__DIR__ . '/storage/logos');
$assetFile = static function ($path) use ($logoRoot) {
    if (!$logoRoot || !is_string($path) || !str_starts_with($path, '/storage/logos/')) return null;
    $file = realpath(__DIR__ . $path);
    return $file && str_starts_with($file, $logoRoot . DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
};

if ($format === 'zip') {
    // tempnam() creates a file; appending `.zip` would orphan the original,
    // so we delete the placeholder before writing the archive.
    $zipPath = tempnam(sys_get_temp_dir(), 'logozip_');
    @unlink($zipPath);
    $zipPath .= '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) { http_response_code(500); exit('Bundle unavailable'); }
    foreach ($paths as $key => $path) {
        $file = $assetFile($path);
        if ($file) $zip->addFile($file, (str_starts_with($key, 'ar_') ? 'arabic/' : (str_starts_with($key, 'int_') ? 'international/' : 'bilingual/')) . basename($file));
    }
    $readme = "Logos for {$company['name_en']}\n"
            . "Indexed by Cardify, https://cardify.om/logos\n\n"
            . "All marks are property of their respective owners. These files\n"
            . "contain indexed or verified artwork for reference;\n"
            . "use is permitted for identification and reference only (nominative\n"
            . "fair use). Commercial reuse, redistribution, and derivative works\n"
            . "require the owner's permission.\n\n"
            . "Need business cards? Visit https://cardify.om/pricing\n";
    $identity = LogoLibrary::identityAssets($company);
    if ($identity) {
        if (!empty($identity['layouts']['international'])) $readme .= "International layout: outside the Sultanate of Oman only.\n";
    }
    $zip->addFromString('README.txt', $readme);
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'
        . preg_replace('~[^a-z0-9]+~i', '-', $company['slug']) . '-logos.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
} else {
    $path = $paths[$format] ?? null;
    $file = $assetFile($path);
    if (!$file) {
        http_response_code(404);
        die('File not available');
    }
    $mime = match (true) {
        preg_match('/^((ar|int)_)?svg(_|$)/', $format) === 1 => 'image/svg+xml',
        preg_match('/^((ar|int)_)?pdf(_|$)/', $format) === 1 => 'application/pdf',
        preg_match('/^((ar|int)_)?webp(_|$)/', $format) === 1 => 'image/webp',
        default                                                 => 'image/png',
    };
    header("Content-Type: $mime");
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
}

// Log (non-blocking, failure here shouldn't break the download). The
// cookie id ties the download back to the lead row in logo_leads so
// we know which contact pulled which asset.
try {
    $db->getConnection()->prepare(
        "INSERT INTO logo_downloads
            (company_id, format, ip_hash, user_agent_hash, referrer, unlock_cookie_id)
         VALUES (:cid, :f, :ih, :uh, :r, :ck)"
    )->execute([
        ':cid' => $companyId,
        ':f'   => $format,
        ':ih'  => $ipHash,
        ':uh'  => LogoLibrary::uaHash(),
        ':r'   => $_SERVER['HTTP_REFERER'] ?? null,
        ':ck'  => $unlockCookie ?: null,
    ]);
} catch (Throwable $e) {
    error_log('logo download log failed: ' . $e->getMessage());
}
