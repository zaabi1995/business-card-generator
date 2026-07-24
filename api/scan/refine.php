<?php
/**
 * POST /api/scan/refine.php (multipart: image, optional draft JSON). A Cardify
 * PRO feature: re-reads a business card photo with the server AI (Claude) to
 * rescue fields on-device OCR missed. Gated to Pro accounts (scan_pro_until in
 * the future). Rate-limited (AI calls cost money). Bearer-auth.
 * -> {success, parsed, status}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanParser.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployeeMutation();

// Pro gate: account must currently be Pro (Apple OR web).
$db = Database::getInstance();
$row = $db->fetchOne(
    "SELECT valid_until
     FROM scan_account_entitlements
     WHERE account_id = :account_id
       AND entitlement = 'scan_pro'
       AND status = 'active'
       AND valid_until > NOW()
     LIMIT 1",
    ['account_id' => $ctx['account_id']]
) ?: [];
if (empty($row['valid_until'])) {
    http_response_code(402); echo json_encode(['success' => false, 'error' => 'pro_required']); exit;
}
// Bounded: AI calls cost money.
if (!RateLimiter::check('scan_refine:' . $ctx['account_id'], getClientIp(), 30, 3600)) {
    http_response_code(429); echo json_encode(['success' => false, 'error' => 'rate_limited']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => 'POST an image file']); exit;
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['image']['tmp_name']);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $_FILES['image']['size'] > 10 * 1024 * 1024) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => 'JPEG/PNG/WebP up to 10MB only']); exit;
}
$draft = null;
if (!empty($_POST['draft'])) {
    $d = json_decode($_POST['draft'], true);
    if (is_array($d) && $d !== []) $draft = ScanParser::sanitizeDraft($d);
}
$tmp = sys_get_temp_dir() . '/scanrefine_' . bin2hex(random_bytes(8));
if (!move_uploaded_file($_FILES['image']['tmp_name'], $tmp)) {
    http_response_code(500); echo json_encode(['success' => false, 'error' => 'store_failed']); exit;
}
try {
    $r = ScanParser::refine($tmp, $draft);
} finally {
    @unlink($tmp);
}
if (empty($r['success'])) {
    echo json_encode(['success' => false, 'error' => $r['error'] ?? 'refine_failed']); exit;
}
echo json_encode(['success' => true, 'parsed' => $r['parsed'], 'status' => 'refined'], JSON_UNESCAPED_UNICODE);
