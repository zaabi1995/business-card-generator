<?php
/**
 * POST /api/scan/upload.php, business card photo (+ optional device draft) -> parsed contact
 *
 * Body: multipart, image (file, required), draft (JSON string, optional),
 * device_uuid, parse_tier, met_where, met_at, tags (all optional).
 * Response: {success, scan: {id, parsed, status, image_url}}.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanParser.php';
require_once INCLUDES_DIR . '/ShadowProfileService.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'POST an image file']);
    exit;
}
if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Upload failed, error code ' . $_FILES['image']['error']]);
    exit;
}

// Real MIME detection, never trust the client-supplied type.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['image']['tmp_name']);
$extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extMap[$mime]) || $_FILES['image']['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JPEG/PNG/WebP up to 10MB only']);
    exit;
}

// $ctx['employee_id'] is a VARCHAR(36) UUID string, used verbatim as the
// upload directory name, never cast to int.
$dir = __DIR__ . '/../../uploads/scans/' . $ctx['employee_id'];
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Store failed']);
    exit;
}
$fname = bin2hex(random_bytes(12)) . '.' . $extMap[$mime];
$dest = $dir . '/' . $fname;
if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Store failed']);
    exit;
}
@chmod($dest, 0644);
$relPath = 'uploads/scans/' . $ctx['employee_id'] . '/' . $fname;

$draft = null;
if (!empty($_POST['draft'])) {
    $d = json_decode($_POST['draft'], true);
    if (is_array($d)) $draft = $d;
}

$refined = ScanParser::refine($dest, $draft);
$parsed = $refined['success'] ? $refined['parsed'] : ($draft ?: ScanParser::emptyParsed());
$status = $refined['success'] ? 'refined' : ($draft ? 'parsed' : 'failed');
$shadowId = ShadowProfileService::upsertFromParsed($parsed);

$db = Database::getInstance();
$deviceUuid = substr(trim($_POST['device_uuid'] ?? ''), 0, 64) ?: null;

// Offline sync idempotency: same device_uuid for this employee updates the
// existing scan rather than duplicating it (backed by the scans table's
// uniq_emp_device unique key on (employee_id, device_uuid)).
$existing = $deviceUuid ? $db->fetchOne(
    "SELECT id FROM scans WHERE employee_id = :e AND device_uuid = :d",
    ['e' => $ctx['employee_id'], 'd' => $deviceUuid]
) : null;

$fields = [
    'employee_id' => $ctx['employee_id'],
    'company_id' => $ctx['company_id'],
    'device_uuid' => $deviceUuid,
    'image_path' => $relPath,
    'parsed' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
    'parse_tier' => $refined['success'] ? 3 : (int)($_POST['parse_tier'] ?? 0),
    'status' => $status,
    'tags' => substr(trim($_POST['tags'] ?? ''), 0, 500) ?: null,
    'met_where' => substr(trim($_POST['met_where'] ?? ''), 0, 255) ?: null,
    'met_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['met_at'] ?? '') ? $_POST['met_at'] : null,
    'shadow_profile_id' => $shadowId,
];

if ($existing) {
    $scanId = updateScan($db, (int)$existing['id'], $fields);
} else {
    try {
        $scanId = (int)$db->insert('scans', $fields);
    } catch (\PDOException $e) {
        // Duplicate-key race on uniq_emp_device: a concurrent resubmit for
        // the same device_uuid (flaky network retry) landed its INSERT
        // first. Re-look-up and fall through to the update path, same
        // retry-once pattern as ShadowProfileService::upsertFromParsed().
        if ($deviceUuid === null || (string)$e->getCode() !== '23000') throw $e;
        $existing = $db->fetchOne(
            "SELECT id FROM scans WHERE employee_id = :e AND device_uuid = :d",
            ['e' => $ctx['employee_id'], 'd' => $deviceUuid]
        );
        if (!$existing) throw $e;
        $scanId = updateScan($db, (int)$existing['id'], $fields);
    }
}

echo json_encode(['success' => true, 'scan' => [
    'id' => $scanId,
    'parsed' => $parsed,
    'status' => $status,
    'image_url' => '/' . $relPath,
]], JSON_UNESCAPED_UNICODE);

function updateScan(Database $db, int $scanId, array $fields): int {
    $db->getConnection()->prepare(
        "UPDATE scans SET image_path=?, parsed=?, parse_tier=?, status=?, shadow_profile_id=? WHERE id=?"
    )->execute([
        $fields['image_path'], $fields['parsed'], $fields['parse_tier'],
        $fields['status'], $fields['shadow_profile_id'], $scanId,
    ]);
    return $scanId;
}
