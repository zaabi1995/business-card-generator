<?php
/**
 * Signed logo download endpoint with rate limit + analytics.
 *
 * Only verified logos are downloadable. Each download logged to
 * logo_downloads (hashed IP/UA). Rate limit: 30/hour/IP.
 *
 * GET /logo-download?company=NNN&format=svg|png_512|png_1024|png_2048|webp|zip
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

$db = Database::getInstance();
$companyId = (int) ($_GET['company'] ?? 0);
$format    = $_GET['format'] ?? '';
$allowed   = ['svg', 'png_512', 'png_1024', 'png_2048', 'webp', 'zip'];

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
    http_response_code(403);
    die('Downloads are only available for verified logos. Claim this profile to verify.');
}

// Rate limit: 30 downloads/hour per IP, atomic via rate_limits table
// (non-atomic SELECT COUNT then INSERT lets parallel fetches bypass the cap).
$ipHash = LogoLibrary::ipHash();
$bucket = (int) floor(time() / 3600);
$db->getConnection()->prepare(
    "INSERT INTO rate_limits (action, ip, bucket, count, window_sec)
     VALUES ('logo_download', :ip, :b, 1, 3600)
     ON DUPLICATE KEY UPDATE count = count + 1"
)->execute([':ip' => $ipHash, ':b' => $bucket]);
$recent = (int) ($db->fetchOne(
    "SELECT count FROM rate_limits WHERE action = 'logo_download' AND ip = :ip AND bucket = :b",
    [':ip' => $ipHash, ':b' => $bucket]
)['count'] ?? 0);
if ($recent > 30) {
    http_response_code(429);
    header('Retry-After: 3600');
    die('Rate limit exceeded');
}

$col = match ($format) {
    'svg'      => 'logo_svg_path',
    'png_512'  => 'logo_png_512_path',
    'png_1024' => 'logo_png_path',
    'png_2048' => 'logo_png_2048_path',
    'webp'     => 'logo_webp_path',
    'zip'      => null,
};

if ($format === 'zip') {
    // tempnam() creates a file; appending `.zip` would orphan the original,
    // so we delete the placeholder before writing the archive.
    $zipPath = tempnam(sys_get_temp_dir(), 'logozip_');
    @unlink($zipPath);
    $zipPath .= '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $root = __DIR__;
    foreach (
        ['logo_svg_path', 'logo_png_path', 'logo_png_512_path', 'logo_png_2048_path', 'logo_webp_path']
        as $key
    ) {
        if (!empty($company[$key]) && is_file($root . $company[$key])) {
            $zip->addFile($root . $company[$key], basename($company[$key]));
        }
    }
    $readme = "Logo bundle for {$company['name_en']}\n"
            . "Indexed by Cardify — https://cardify.om/logos\n\n"
            . "All marks are property of their respective owners. This bundle\n"
            . "is published because the brand owner has verified their profile;\n"
            . "use is permitted for identification and reference only (nominative\n"
            . "fair use). Commercial reuse, redistribution, and derivative works\n"
            . "require the owner's permission.\n\n"
            . "Need business cards? Visit https://cardify.om/pricing\n";
    $zip->addFromString('README.txt', $readme);
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'
        . preg_replace('~[^a-z0-9]+~i', '-', $company['slug']) . '-logos.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
} else {
    $path = $company[$col] ?? null;
    if (!$path || !is_file(__DIR__ . $path)) {
        http_response_code(404);
        die('File not available');
    }
    $mime = match ($format) {
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        default => 'image/png',
    };
    header("Content-Type: $mime");
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize(__DIR__ . $path));
    readfile(__DIR__ . $path);
}

// Log (non-blocking — failure here shouldn't break the download)
try {
    $db->getConnection()->prepare(
        "INSERT INTO logo_downloads (company_id, format, ip_hash, user_agent_hash, referrer)
         VALUES (:cid, :f, :ih, :uh, :r)"
    )->execute([
        ':cid' => $companyId,
        ':f'   => $format,
        ':ih'  => $ipHash,
        ':uh'  => LogoLibrary::uaHash(),
        ':r'   => $_SERVER['HTTP_REFERER'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('logo download log failed: ' . $e->getMessage());
}
