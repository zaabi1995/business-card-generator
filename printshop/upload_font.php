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
//
// The posted value lands in a filesystem path below, so it is constrained
// to the companies.id column shape (VARCHAR(36) UUID) before use. Without
// this a caller whose session carries no company_id (a super_admin is the
// normal case) could post "../../.." and drive mkdir/move_uploaded_file
// outside /uploads/fonts/companies.
$companyId = function_exists('getCurrentCompanyId') ? getCurrentCompanyId() : null;
if (!$companyId) {
    $postedCompanyId = trim((string) ($_POST['company_id'] ?? ''));
    if ($postedCompanyId !== '') {
        if (!preg_match('/^[A-Za-z0-9-]{1,36}$/', $postedCompanyId)) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_company_id']);
            exit;
        }
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

// Defense in depth: extension whitelist + magic-byte sniff. finfo MIME
// detection is unreliable for fonts (returns x-font-truetype, sfnt,
// vnd.ms-opentype, octet-stream depending on libmagic version), so we
// check the actual file signature instead. CompanyFonts::describeFile
// validates the font structure on the next call.
$fp = @fopen($_FILES['font_file']['tmp_name'], 'rb');
$magic = $fp ? fread($fp, 4) : '';
if ($fp) fclose($fp);
$validMagic = [
    'wOF2',                         // woff2
    'wOFF',                         // woff
    "\x00\x01\x00\x00",         // ttf (TrueType)
    'true',                         // ttf (legacy Apple)
    'typ1',                         // ttf (PostScript Type 1 in sfnt)
    'OTTO',                         // otf (CFF/OpenType)
];
if (!in_array($magic, $validMagic, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_font_file', 'detail' => 'magic bytes did not match any supported font format']);
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
