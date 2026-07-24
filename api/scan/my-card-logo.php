<?php
/**
 * POST /api/scan/my-card-logo.php (multipart, field "logo") -> uploads a logo
 * for the signed-in employee's company card and persists it on
 * company_themes.logo_path (the same store admin/theme.php + my-card.php use),
 * so the designer's logo now survives a save. Company-scoped, like the brand
 * colour. Bearer-auth. -> {success, logo_url}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'my_card_logo', 120);

// Company logo is company-scoped: on a MANAGED company only the admin / super-
// admin may change it. Soft 200 + brand_locked flag (same contract as
// my-card.php) so the app treats it as a lock, not a hard error.
require_once __DIR__ . '/_brand_guard.php';
if (!scanCanEditBrand(
    Database::getInstance(),
    (string) $ctx['account_id'],
    (string) $ctx['employee_id']
)) {
    echo json_encode(['success' => false, 'error' => 'brand_locked']);
    exit;
}

// Clear branch: remove the card's logo (no file needed).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['clear'])) {
    $db = Database::getInstance();
    $theme = $db->fetchOne("SELECT id FROM company_themes WHERE company_id = :cid", ['cid' => $ctx['company_id']]);
    if ($theme) { $db->update('company_themes', ['logo_path' => null], 'id = :id', ['id' => $theme['id']]); }
    echo json_encode(['success' => true, 'logo_url' => null]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['logo'])) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => 'POST a logo file']); exit;
}
if ($_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => 'upload_failed']); exit;
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['logo']['tmp_name']);
$extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extMap[$mime]) || $_FILES['logo']['size'] > 5 * 1024 * 1024) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => 'JPEG/PNG/WebP up to 5MB only']); exit;
}

$companyId = $ctx['company_id'];
$rel = 'companies/' . $companyId . '/theme';
$dir = __DIR__ . '/../../uploads/' . $rel;
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500); echo json_encode(['success' => false, 'error' => 'store_failed']); exit;
}
$fname = 'scan_logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $fname)) {
    http_response_code(500); echo json_encode(['success' => false, 'error' => 'store_failed']); exit;
}
$logoPath = $rel . '/' . $fname; // stored WITHOUT the uploads/ prefix (see my-card.php)

try {
    $db = Database::getInstance();
    $theme = $db->fetchOne("SELECT id, logo_path FROM company_themes WHERE company_id = :cid", ['cid' => $companyId]);
    if ($theme) {
        $db->update('company_themes', ['logo_path' => $logoPath], 'id = :id', ['id' => $theme['id']]);
        // best-effort: drop the previous scan logo (only ours, only in theme dir)
        $old = (string)($theme['logo_path'] ?? '');
        if ($old !== '' && strpos($old, 'companies/' . $companyId . '/theme/scan_logo_') === 0) {
            @unlink(__DIR__ . '/../../uploads/' . ltrim($old, '/'));
        }
    } else {
        $db->insert('company_themes', ['id' => generateUUID(), 'company_id' => $companyId, 'logo_path' => $logoPath, 'primary_color' => '#009bc1']);
    }
} catch (\Throwable $e) {
    @unlink($dir . '/' . $fname);
    error_log('[scan/my-card-logo] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['success' => false, 'error' => 'server_error']); exit;
}

$logoUrl = 'https://' . cardifyApexHost() . '/uploads/' . $logoPath;
echo json_encode(['success' => true, 'logo_url' => $logoUrl]);
