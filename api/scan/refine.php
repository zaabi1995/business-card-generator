<?php
/**
 * POST /api/scan/refine.php (multipart: image, optional draft JSON). A Cardify
 * PRO feature: re-reads a business card photo with the server AI (Claude) to
 * rescue fields on-device OCR missed. Gated to Pro ACCOUNTS via
 * scan_account_entitlements. Rate-limited per account (AI calls cost money).
 * Bearer-auth.
 * -> {success, parsed, status}
 *
 * Account scoping, added 9 Aug 2026. This endpoint was the last mutating scan
 * endpoint still on the pre-account model, and every part of that was a money
 * bug on a paid path:
 *
 *   - it called requireEmployee() rather than requireEmployeeMutation(), so it
 *     was the ONLY mutating scan endpoint with no per-account GET_LOCK and no
 *     re-authentication after the wait. A token revoked, or an account
 *     switched, while the request queued was still honoured.
 *   - it read Pro from employees.scan_pro_until, a MIRROR column. The
 *     authority is scan_account_entitlements (pro-status.php and
 *     pro-report.php both read it; migration 141 backfilled it from the
 *     mirror). Reading the mirror means an entitlement revoked on the account
 *     still bought AI calls until the mirror happened to be rewritten.
 *   - it keyed its 30/hr limit on employee_id, so an account holding N
 *     profiles got N times the paid-AI budget from ONE subscription.
 *
 * Now identical to update.php / create.php / my-card.php: requireEmployeeMutation()
 * then scanRateLimit($ctx, ...), which keys on $ctx['account_id'].
 *
 * The account lock is held across the AI call, which is deliberate rather than
 * incidental: it is what stops two concurrent refines from both passing a
 * 29-of-30 budget check and billing twice.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanParser.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployeeMutation();

// Pro gate: the ACCOUNT must currently be Pro (Apple OR web). Same query
// pro-status.php answers with, so what the settings screen shows and what this
// endpoint enforces cannot drift apart.
$db = Database::getInstance();
$row = $db->fetchOne(
    "SELECT valid_until
     FROM scan_account_entitlements
     WHERE account_id = :account_id
       AND entitlement = 'scan_pro'
       AND status = 'active'
     LIMIT 1",
    ['account_id' => $ctx['account_id']]
) ?: [];
if (empty($row['valid_until']) || strtotime((string) $row['valid_until']) <= time()) {
    http_response_code(402); echo json_encode(['success' => false, 'error' => 'pro_required']); exit;
}
// Bounded per ACCOUNT: AI calls cost money, and one subscription is one budget
// no matter how many profiles it carries.
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'refine', 30, 3600);
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
