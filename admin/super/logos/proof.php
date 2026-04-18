<?php
/**
 * Admin-auth'd serving for claim proof files stored under
 * /storage/logos/pending/ (nginx + .htaccess both deny direct access).
 *
 * GET /admin/super/logos/proof.php?claim=NNN
 *
 * Streams the file referenced by logo_claims.proof_url, gated by super_admin.
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$claimId = (int) ($_GET['claim'] ?? 0);
if ($claimId <= 0) {
    http_response_code(400);
    die('Bad request');
}

$claim = $db->fetchOne(
    "SELECT proof_url FROM logo_claims WHERE id = :id",
    [':id' => $claimId]
);
$relPath = $claim['proof_url'] ?? null;

// Guard: only serve files under /storage/logos/pending/
if (!$relPath || strpos($relPath, '/storage/logos/pending/') !== 0) {
    http_response_code(404);
    die('Not found');
}

$root = dirname(__DIR__, 3);
$abs  = $root . $relPath;
if (!is_file($abs)) {
    http_response_code(404);
    die('File missing');
}

$ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'pdf'          => 'application/pdf',
    'png'          => 'image/png',
    'jpg', 'jpeg'  => 'image/jpeg',
    default        => 'application/octet-stream',
};

header("Content-Type: $mime");
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="claim-' . $claimId . '.' . $ext . '"');
header('Cache-Control: private, no-store');
readfile($abs);
