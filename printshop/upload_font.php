<?php
/**
 * Print Shop: Upload a font file for the current company.
 *
 * Used by the onboarding review pane when the parser flagged the
 * source PDF as referencing a font we don't yet have on the server
 * (e.g. a paid Lato-Medium variant). The admin drops the .woff2 /
 * .ttf / .otf and Cardify wires it into the per-company font dir;
 * the editor + portal pick it up via @font-face on the next render.
 *
 * Body (multipart/form-data):
 *   font_file  - the font binary (.woff2 / .woff / .ttf / .otf)
 *   raw_name   - optional, e.g. "Lato-Medium" so the saved filename
 *                matches the parser's missing_fonts entry exactly
 *
 * Auth: company admin / company / admin / super_admin.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CompanyFonts.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::getCurrentUser();
$allowedRoles = ['admin', 'company_admin', 'company', 'super_admin'];
if (!in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// Accept CSRF token from either FormData (csrf_token) or header (X-CSRF-Token)
// since callers built before this hardening pass send it as a header.
$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!validateCSRFToken($csrf)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_csrf']);
    exit;
}

// Tenant-admin path uses session company_id; print-shop operator path
// posts company_id explicitly when uploading on behalf of a tenant.
$companyId = function_exists('getCurrentCompanyId') ? getCurrentCompanyId() : null;
if (!$companyId) {
    $postedCompanyId = trim((string) ($_POST['company_id'] ?? ''));
    if ($postedCompanyId !== '') {
        $companyId = $postedCompanyId;
    }
}
if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'no_company_context']);
    exit;
}

if (empty($_FILES['font_file']) || $_FILES['font_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'no_file_uploaded']);
    exit;
}

$MAX_BYTES = 5 * 1024 * 1024; // 5 MB per font, generous for OTF/TTF
if ((int)$_FILES['font_file']['size'] > $MAX_BYTES) {
    http_response_code(413);
    echo json_encode(['error' => 'font_too_large', 'max_mb' => 5]);
    exit;
}

$origName = $_FILES['font_file']['name'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!in_array($ext, ['woff2', 'woff', 'ttf', 'otf'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported_format', 'allowed' => ['woff2','woff','ttf','otf']]);
    exit;
}

// Defense in depth: extension whitelist + finfo (cardify rule 7).
// $_FILES['type'] is client-controlled. finfo trusts file content.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$realMime = $finfo->file($_FILES['font_file']['tmp_name']);
$allowedMimes = [
    'font/woff2', 'application/font-woff2',
    'font/woff',  'application/font-woff',
    'font/ttf',   'application/x-font-ttf', 'application/font-sfnt',
    'font/otf',   'application/x-font-otf',
    'application/octet-stream', // PHP+finfo often falls back here on .ttf/.otf
];
if (!in_array($realMime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported_mime', 'detected' => $realMime]);
    exit;
}

// Save under /uploads/fonts/companies/<companyId>/<sanitised>.<ext>.
// Prefer the raw_name posted by the wizard (e.g. "Lato-Medium") so the
// saved filename matches what the parser reports as missing; fall back
// to the original filename basename if the client didn't provide one.
$rawName = isset($_POST['raw_name']) ? (string)$_POST['raw_name'] : pathinfo($origName, PATHINFO_FILENAME);
$rawName = preg_replace('/[^A-Za-z0-9._\-]+/', '', $rawName);
if ($rawName === '') $rawName = 'Custom';
$saveName = $rawName . '.' . $ext;

$companyDirRel = CompanyFonts::COMPANY_DIR . '/' . $companyId;
$companyDirAbs = realpath(__DIR__ . '/..') . $companyDirRel;
if (!is_dir($companyDirAbs)) {
    @mkdir($companyDirAbs, 0755, true);
}

$dest = $companyDirAbs . '/' . $saveName;
if (!move_uploaded_file($_FILES['font_file']['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['error' => 'save_failed']);
    exit;
}
@chmod($dest, 0644);

$desc = CompanyFonts::describeFile($saveName);

echo json_encode([
    'ok'         => true,
    'family'     => $desc['family']  ?? null,
    'weight'     => $desc['weight']  ?? null,
    'style'      => $desc['style']   ?? null,
    'file'       => $companyDirRel . '/' . $saveName,
    'saved_as'   => $saveName,
]);
