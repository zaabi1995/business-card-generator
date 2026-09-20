<?php
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanImageAccess.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
$ctx = ScanAuth::requireEmployee();
$path = ScanImageAccess::resolve(
    Database::getInstance(), (string)$ctx['employee_id'], (int)($_GET['id'] ?? 0),
    (string)($_GET['side'] ?? 'front'), dirname(__DIR__, 2)
);
if ($path === null) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Disposition: inline');
readfile($path);
