<?php
/**
 * Admin-auth'd preview for /storage/logos/pending/ files.
 *
 * Nginx denies direct access to /storage/logos/pending/ (trademarked logos
 * awaiting admin review shouldn't be fetchable via guessed URLs). This
 * endpoint streams the file only to super_admins.
 *
 * GET /admin/super/logos/pending-preview.php?id=NNN&ext=svg|png|jpg|jpeg|webp
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::requireRole('super_admin');

$id  = (int) ($_GET['id'] ?? 0);
$ext = strtolower($_GET['ext'] ?? '');

if (!$id || !in_array($ext, ['svg', 'png', 'jpg', 'jpeg', 'webp'], true)) {
    http_response_code(400);
    die('Bad request');
}

$root = dirname(__DIR__, 3);
$path = $root . "/storage/logos/pending/{$id}.{$ext}";

if (!is_file($path)) {
    http_response_code(404);
    die('Not found');
}

$mime = match ($ext) {
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    default => 'image/jpeg',
};

header("Content-Type: $mime");
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
