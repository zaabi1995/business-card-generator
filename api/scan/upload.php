<?php
/**
 * POST /api/scan/upload.php, business card photo (+ optional device draft) -> parsed contact
 *
 * Body: multipart, image (file, required), image_back (file, optional),
 * draft (JSON string, optional), device_uuid, parse_tier, met_where,
 * met_at, tags (all optional).
 * Response: {success, scan: {id, parsed, status, image_url, image_back_url}}.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanParser.php';
require_once INCLUDES_DIR . '/ShadowProfileService.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'upload', 240);

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

// Back-side photo is optional but follows the same rules as the front.
$mimeBack = null;
if (!empty($_FILES['image_back'])) {
    if ($_FILES['image_back']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Back image upload failed, error code ' . $_FILES['image_back']['error']]);
        exit;
    }
    $mimeBack = $finfo->file($_FILES['image_back']['tmp_name']);
    if (!isset($extMap[$mimeBack]) || $_FILES['image_back']['size'] > 10 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Back image: JPEG/PNG/WebP up to 10MB only']);
        exit;
    }
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

// Store the back-side photo in the same directory, same naming scheme.
$destBack = null;
$relPathBack = null;
if ($mimeBack !== null) {
    $fnameBack = bin2hex(random_bytes(12)) . '.' . $extMap[$mimeBack];
    $destBack = $dir . '/' . $fnameBack;
    if (!move_uploaded_file($_FILES['image_back']['tmp_name'], $destBack)) {
        @unlink($dest);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Store failed']);
        exit;
    }
    @chmod($destBack, 0644);
    $relPathBack = 'uploads/scans/' . $ctx['employee_id'] . '/' . $fnameBack;
}

// The device draft is untrusted client JSON: sanitize to the canonical
// parse shape (unknown keys dropped, strings capped, phones/emails bounded)
// before it can reach the prompt or be stored verbatim on refine failure.
$draft = null;
if (!empty($_POST['draft'])) {
    $d = json_decode($_POST['draft'], true);
    if (is_array($d) && $d !== []) $draft = ScanParser::sanitizeDraft($d);
}

// From here on the photo is on disk; any failure must still answer JSON
// and must not leave an orphaned file behind.
try {
    // Parsing is device-first by product decision (13 Jul 2026): when the app
    // supplies a device-parsed draft, the server stores it as-is and never
    // calls an AI API. Server refine only runs for draftless uploads AND only
    // when explicitly enabled via system_settings scan_server_refine = 'on'.
    $refined = ['success' => false, 'parsed' => null, 'error' => 'refine_disabled'];
    if (!$draft && ScanParser::serverRefineEnabled()) {
        $refined = ScanParser::refine($dest, null);
    }
    $parsed = $refined['success'] ? $refined['parsed'] : ($draft ?: ScanParser::emptyParsed());
    $status = $refined['success'] ? 'refined' : ($draft ? 'parsed' : 'failed');
    $shadowId = ShadowProfileService::upsertFromParsed($parsed);

    $db = Database::getInstance();
    $deviceUuid = substr(trim($_POST['device_uuid'] ?? ''), 0, 64) ?: null;

    $metAt = null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $_POST['met_at'] ?? '', $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $metAt = $m[0];
    }

    // Offline sync idempotency: same device_uuid for this employee updates the
    // existing scan rather than duplicating it (backed by the scans table's
    // uniq_emp_device unique key on (employee_id, device_uuid)).
    $existing = $deviceUuid ? $db->fetchOne(
        "SELECT id, image_path, image_path_back FROM scans WHERE employee_id = :e AND device_uuid = :d",
        ['e' => $ctx['employee_id'], 'd' => $deviceUuid]
    ) : null;

    $fields = [
        'employee_id' => $ctx['employee_id'],
        'company_id' => $ctx['company_id'],
        'device_uuid' => $deviceUuid,
        'image_path' => $relPath,
        // Only replace the back image when a new one was uploaded, otherwise
        // keep whatever the row already had (resync often only sends front).
        'image_path_back' => $relPathBack !== null ? $relPathBack : ($existing['image_path_back'] ?? null),
        'parsed' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
        // Bounded to the TINYINT range the column expects; a huge client
        // value must never throw under strict sql_mode.
        'parse_tier' => $refined['success'] ? 3 : max(0, min(3, (int)($_POST['parse_tier'] ?? 0))),
        'status' => $status,
        'tags' => substr(trim($_POST['tags'] ?? ''), 0, 500) ?: null,
        'met_where' => substr(trim($_POST['met_where'] ?? ''), 0, 255) ?: null,
        'met_at' => $metAt,
        'shadow_profile_id' => $shadowId,
    ];

    if ($existing) {
        $scanId = updateScan($db, (int)$existing['id'], $fields, $existing['image_path'] ?? null, $existing['image_path_back'] ?? null);
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
                "SELECT id, image_path, image_path_back FROM scans WHERE employee_id = :e AND device_uuid = :d",
                ['e' => $ctx['employee_id'], 'd' => $deviceUuid]
            );
            if (!$existing) throw $e;
            $scanId = updateScan($db, (int)$existing['id'], $fields, $existing['image_path'] ?? null, $existing['image_path_back'] ?? null);
        }
    }
} catch (\Throwable $e) {
    // Photo cleanup contract: never keep a file for a scan row that was
    // never written, and always answer JSON even on unexpected failure.
    @unlink($dest);
    if ($destBack !== null) @unlink($destBack);
    error_log('[scan/upload] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['success' => true, 'scan' => [
    'id' => $scanId,
    'parsed' => $parsed,
    'status' => $status,
    'image_url' => '/' . $relPath,
    'image_back_url' => $fields['image_path_back'] ? '/' . $fields['image_path_back'] : null,
]], JSON_UNESCAPED_UNICODE);

function updateScan(Database $db, int $scanId, array $fields, ?string $oldImagePath, ?string $oldImagePathBack = null): int {
    $db->getConnection()->prepare(
        "UPDATE scans SET image_path=?, image_path_back=?, parsed=?, parse_tier=?, status=?, shadow_profile_id=? WHERE id=?"
    )->execute([
        $fields['image_path'], $fields['image_path_back'], $fields['parsed'], $fields['parse_tier'],
        $fields['status'], $fields['shadow_profile_id'], $scanId,
    ]);
    // The resynced photo replaced the old one; drop the orphan(s). Path guard
    // is belt-and-braces: only delete inside uploads/scans/, never a traversal.
    unlinkOldScanImage($oldImagePath, $fields['image_path']);
    unlinkOldScanImage($oldImagePathBack, $fields['image_path_back']);
    return $scanId;
}

function unlinkOldScanImage(?string $oldPath, ?string $newPath): void {
    if ($oldPath !== null && $oldPath !== '' && $oldPath !== $newPath
        && strpos($oldPath, 'uploads/scans/') === 0
        && strpos($oldPath, '..') === false) {
        $old = __DIR__ . '/../../' . $oldPath;
        if (is_file($old)) @unlink($old);
    }
}
