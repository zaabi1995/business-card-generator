<?php
/**
 * Token-guarded employee photo upload. Accepts PNG/JPEG/WebP, max 5MB,
 * resizes via GD to a 512x512 center crop, writes both WebP (primary)
 * and PNG (fallback) to storage/employee-photos/<company_id>/, and
 * updates employees.photo to the WebP path.
 *
 * GD is used instead of ImageMagick because the VPS lacks libMagickWand;
 * GD >= 2.2.0 bundled with PHP 7.1+ supports all three in + out formats.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/EmployeeEditToken.php';
require_once INCLUDES_DIR . '/RateLimiter.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$token = trim($_POST['token'] ?? '');
$employee = EmployeeEditToken::verify($token);
if (!$employee) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'token_invalid_or_expired']);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!RateLimiter::check('emp_photo:' . substr(hash('sha256', $token), 0, 16), $ip, 5, 60)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

$file = $_FILES['photo'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'upload_failed']);
    exit;
}

if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'file_too_large']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$realMime = $finfo->file($file['tmp_name']);
$allowed = ['image/png' => 'png', 'image/jpeg' => 'jpeg', 'image/webp' => 'webp'];
if (!isset($allowed[$realMime])) {
    http_response_code(415);
    echo json_encode(['ok' => false, 'error' => 'bad_mime', 'got' => $realMime]);
    exit;
}

$src = null;
switch ($allowed[$realMime]) {
    case 'png':  $src = @imagecreatefrompng ($file['tmp_name']); break;
    case 'jpeg': $src = @imagecreatefromjpeg($file['tmp_name']); break;
    case 'webp': $src = @imagecreatefromwebp($file['tmp_name']); break;
}
if (!$src) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'decode_failed']);
    exit;
}

$sw = imagesx($src); $sh = imagesy($src);
$side = min($sw, $sh);
$sx = intdiv($sw - $side, 2); $sy = intdiv($sh - $side, 2);

$out = imagecreatetruecolor(512, 512);
imagesavealpha($out, true);
imagealphablending($out, false);
$transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
imagefilledrectangle($out, 0, 0, 512, 512, $transparent);
imagealphablending($out, true);
imagecopyresampled($out, $src, 0, 0, $sx, $sy, 512, 512, $side, $side);
imagedestroy($src);

$companyId = $employee['company_id'];
$dir = __DIR__ . '/../storage/employee-photos/' . $companyId;
if (!is_dir($dir)) @mkdir($dir, 0755, true);
if (!is_dir($dir) || !is_writable($dir)) {
    imagedestroy($out);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'storage_unwritable']);
    exit;
}

$empId = $employee['id'];
$webp = $dir . '/' . $empId . '.webp';
$png  = $dir . '/' . $empId . '.png';
$webpOk = imagewebp($out, $webp, 85);
$pngOk  = imagepng($out, $png, 6);
imagedestroy($out);

if (!$webpOk || !$pngOk) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'write_failed']);
    exit;
}
@chmod($webp, 0644); @chmod($png, 0644);

$publicPath = '/storage/employee-photos/' . $companyId . '/' . $empId . '.webp';
try {
    $db = Database::getInstance();
    $db->update('employees', ['photo' => $publicPath], 'id = :id', ['id' => $empId]);
} catch (Throwable $e) {
    error_log('[photo-upload] db update failed: ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'photo' => $publicPath]);
