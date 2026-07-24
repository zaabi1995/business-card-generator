<?php
/**
 * POST /api/scan/create.php, JSON -> a photo-less contact (imported from the
 * device address book, or a manual/typed card, or a scanned card whose cached
 * photo was lost). Mirrors upload.php's DB write minus all file handling so the
 * parsed contact still backs up to the account and syncs to every device.
 *
 * Body (JSON): { draft (parsed contact, required), device_uuid, met_where,
 * met_at, tags, parse_tier } (all but draft optional).
 * Response: {success, scan: {id, parsed, status, image_url:null, image_back_url:null}}.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanParser.php';
require_once INCLUDES_DIR . '/ShadowProfileService.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'create', 600);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

// The contact is untrusted client JSON: sanitize to the canonical parse shape
// (unknown keys dropped, strings capped, phones/emails bounded) exactly like
// upload.php does for the device draft.
$draftRaw = $body['draft'] ?? null;
$draft = (is_array($draftRaw) && $draftRaw !== []) ? ScanParser::sanitizeDraft($draftRaw) : null;

try {
    $parsed = $draft ?: ScanParser::emptyParsed();
    $status = $draft ? 'parsed' : 'failed';
    $shadowId = ShadowProfileService::upsertFromParsed($parsed);

    $db = Database::getInstance();
    $deviceUuid = substr(trim((string)($body['device_uuid'] ?? '')), 0, 64) ?: null;

    $metAt = null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)($body['met_at'] ?? ''), $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $metAt = $m[0];
    }

    // Same device_uuid idempotency as upload.php (uniq_emp_device).
    $existing = $deviceUuid ? $db->fetchOne(
        "SELECT id FROM scans WHERE employee_id = :e AND device_uuid = :d",
        ['e' => $ctx['employee_id'], 'd' => $deviceUuid]
    ) : null;

    $fields = [
        'employee_id' => $ctx['employee_id'],
        'company_id' => $ctx['company_id'],
        'device_uuid' => $deviceUuid,
        'image_path' => null,
        'image_path_back' => null,
        'parsed' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
        'parse_tier' => max(0, min(3, (int)($body['parse_tier'] ?? 0))),
        'status' => $status,
        'tags' => substr(trim((string)($body['tags'] ?? '')), 0, 500) ?: null,
        'met_where' => substr(trim((string)($body['met_where'] ?? '')), 0, 255) ?: null,
        'met_at' => $metAt,
        'shadow_profile_id' => $shadowId,
    ];

    if ($existing) {
        // Photo-less update: never clears an existing image_path (a text resync
        // must not wipe a photo the row already has on the server).
        $db->getConnection()->prepare(
            "UPDATE scans SET parsed=?, parse_tier=?, status=?, tags=?, met_where=?, met_at=?, shadow_profile_id=? WHERE id=?"
        )->execute([
            $fields['parsed'], $fields['parse_tier'], $fields['status'], $fields['tags'],
            $fields['met_where'], $fields['met_at'], $fields['shadow_profile_id'], (int)$existing['id'],
        ]);
        $scanId = (int)$existing['id'];
    } else {
        try {
            $scanId = (int)$db->insert('scans', $fields);
        } catch (\PDOException $e) {
            // Duplicate-key race on uniq_emp_device (flaky-network retry).
            if ($deviceUuid === null || (string)$e->getCode() !== '23000') throw $e;
            $existing = $db->fetchOne(
                "SELECT id FROM scans WHERE employee_id = :e AND device_uuid = :d",
                ['e' => $ctx['employee_id'], 'd' => $deviceUuid]
            );
            if (!$existing) throw $e;
            $db->getConnection()->prepare(
                "UPDATE scans SET parsed=?, parse_tier=?, status=?, tags=?, met_where=?, met_at=?, shadow_profile_id=? WHERE id=?"
            )->execute([
                $fields['parsed'], $fields['parse_tier'], $fields['status'], $fields['tags'],
                $fields['met_where'], $fields['met_at'], $fields['shadow_profile_id'], (int)$existing['id'],
            ]);
            $scanId = (int)$existing['id'];
        }
    }
} catch (\Throwable $e) {
    error_log('[scan/create] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['success' => true, 'scan' => [
    'id' => $scanId,
    'parsed' => $parsed,
    'status' => $status,
    'image_url' => null,
    'image_back_url' => null,
]], JSON_UNESCAPED_UNICODE);
